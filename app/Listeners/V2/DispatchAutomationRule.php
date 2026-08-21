<?php

declare(strict_types=1);

namespace App\Listeners\V2;

use App\Contracts\Automation\AutomationTriggerEvent;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationStepStatus;
use App\Jobs\V2\RunAutomationAction;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use App\Models\AutomationRule;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Automation\CycleDetector;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single listener that projects every AutomationTriggerEvent onto the rule
 * pipeline.
 *
 * The listener is registered explicitly in AutomationServiceProvider for
 * every concrete event class (one entry per event) so the relationship
 * between an event and the listener is grep-able. It is NOT a wildcard
 * listener — explicit bindings are easier to debug.
 */
class DispatchAutomationRule
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
        private readonly CycleDetector $cycles,
    ) {}

    /**
     * @param  AutomationTriggerEvent&object  $event
     */
    public function handle(object $event): void
    {
        if (! $event instanceof AutomationTriggerEvent) {
            return;
        }

        $eventClass = $event::class;

        $rules = AutomationRule::query()
            ->active()
            ->forTrigger($eventClass)
            ->ordered()
            ->with(['conditionGroups.conditions', 'actions'])
            ->get();

        foreach ($rules as $rule) {
            try {
                $this->processRule($rule, $event);
            } catch (Throwable $e) {
                Log::error('DispatchAutomationRule: rule processing failed', [
                    'rule_id' => $rule->id,
                    'event' => $eventClass,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processRule(AutomationRule $rule, AutomationTriggerEvent $event): void
    {
        $payload = $event->payload();
        $payloadHash = substr(sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 0, 32);

        $subjectType = $event->subjectType() ?? 'unknown';
        $subjectId = (int) ($event->subjectId() ?? 0);

        if ($subjectId <= 0) {
            return;
        }

        if (! $this->evaluator->matches($rule, $payload)) {
            return;
        }

        $idempotencyKey = sha1($rule->id.'|'.$event::class.'|'.$subjectType.'|'.$subjectId.'|'.$payloadHash);

        // Cycle check: if a recent execution for the same (rule, subject) is
        // already in flight, skip dispatch and mark the existing execution
        // (or create a new one) as circuit-broken.
        if ($this->cycles->isCycling((int) $rule->id, $subjectType, $subjectId)) {
            $execution = AutomationExecution::query()
                ->where('rule_id', $rule->id)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('created_at', '>=', now()->subSeconds(CycleDetector::DEFAULT_WINDOW_SECONDS))
                ->orderByDesc('id')
                ->first();

            if ($execution === null) {
                try {
                    $execution = $this->createExecution($rule, $event, $subjectType, $subjectId, $idempotencyKey);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Lost the race: another worker created the row first.
                    return;
                }
            }

            $this->cycles->recordBreak(
                (int) $rule->id,
                $subjectType,
                $subjectId,
                $execution,
                'Re-entry detected within cycle window.',
            );

            return;
        }

        // Create execution row idempotently. UNIQUE on idempotency_key
        // ensures a duplicate event yields a single execution.
        try {
            $execution = $this->createExecution($rule, $event, $subjectType, $subjectId, $idempotencyKey);
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate idempotency_key — already recorded.
            if (Str::contains($e->getMessage(), 'idempotency_key')) {
                return;
            }
            throw $e;
        }

        $isTest = ! $rule->isLiveMode();
        $actions = $rule->actions()->where('is_active', true)->orderBy('position')->get();

        foreach ($actions as $action) {
            $step = AutomationExecutionStep::create([
                'execution_id' => $execution->id,
                'action_id' => $action->id,
                'status' => AutomationStepStatus::PENDING,
                'attempt' => 1,
                'queued_at' => now(),
            ]);

            if ($isTest) {
                $step->status = AutomationStepStatus::SIMULATED;
                $step->started_at = now();
                $step->finished_at = now();
                $step->response_json = [
                    'mode' => 'test',
                    'would_execute' => $action->type,
                    'payload' => $action->payload_json,
                ];
                $step->save();
            } else {
                RunAutomationAction::dispatch($step->id);
            }
        }

        if ($isTest) {
            $execution->status = AutomationExecutionStatus::SUCCESS;
            $execution->finished_at = now();
            $execution->save();
        }
    }

    private function createExecution(
        AutomationRule $rule,
        AutomationTriggerEvent $event,
        string $subjectType,
        int $subjectId,
        string $idempotencyKey,
    ): AutomationExecution {
        return AutomationExecution::create([
            'rule_id' => $rule->id,
            'trigger_event' => $event::class,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'idempotency_key' => $idempotencyKey,
            'status' => AutomationExecutionStatus::QUEUED,
            'attempt' => 1,
        ]);
    }
}