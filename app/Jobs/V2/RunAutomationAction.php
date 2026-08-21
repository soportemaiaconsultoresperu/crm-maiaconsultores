<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationStepStatus;
use App\Jobs\V2\NotifyOnAutomationFailure;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use App\Notifications\Automation\AutomationFailedPermanently;
use App\Services\Automation\ActionRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B12 — Job that runs a single AutomationAction.
 *
 * Constructed by the listener after persisting the execution row and its
 * steps. The Job:
 *  - resolves the action through ActionRegistry
 *  - calls execute()
 *  - records success/failure on the step
 *  - propagates failure to the execution row
 *  - retries on transient errors (`tries=3`, `backoff=[30,120,600]`).
 */
class RunAutomationAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $stepId)
    {
    }

    public function handle(ActionRegistry $registry): void
    {
        $step = AutomationExecutionStep::query()->find($this->stepId);

        if ($step === null) {
            Log::warning('RunAutomationAction: step not found', ['step_id' => $this->stepId]);

            return;
        }

        if (in_array($step->status, [
            AutomationStepStatus::SUCCESS,
            AutomationStepStatus::FAILED,
            AutomationStepStatus::SIMULATED,
            AutomationStepStatus::SKIPPED,
        ], true)) {
            return;
        }

        $step->status = AutomationStepStatus::RUNNING;
        $step->started_at = now();
        $step->attempt = max(1, $this->attempts());
        $step->save();

        $execution = $step->execution()->first();
        $action = $step->action()->first();

        if ($execution === null || $action === null) {
            $step->status = AutomationStepStatus::FAILED;
            $step->error_class = 'MissingExecutionOrAction';
            $step->error_message = 'Execution or action row not found.';
            $step->finished_at = now();
            $step->save();

            return;
        }

        if ($execution->status === AutomationExecutionStatus::QUEUED) {
            $execution->status = AutomationExecutionStatus::RUNNING;
            $execution->started_at = $execution->started_at ?? now();
            $execution->save();
        }

        try {
            $contract = $registry->resolve($action->type);
            $contract->execute((array) ($action->payload_json ?? []), $step);

            $step->refresh();
            $step->status = AutomationStepStatus::SUCCESS;
            $step->finished_at = now();
            $step->save();

            $this->finalizeExecution($execution, $step);
        } catch (Throwable $e) {
            $step->error_class = $e::class;
            $step->error_message = $e->getMessage();
            $step->finished_at = now();
            $step->attempt = max(1, $this->attempts());

            // Final attempt: mark failed permanently. Otherwise leave the
            // step pending so Laravel retries it.
            if ($this->attempts() >= $this->tries) {
                $step->status = AutomationStepStatus::FAILED;
                $step->save();
                $this->finalizeExecution($execution, $step, failed: true);
            } else {
                $step->status = AutomationStepStatus::FAILED;
                $step->save();
                throw $e;
            }
        }
    }

    private function finalizeExecution(AutomationExecution $execution, AutomationExecutionStep $step, bool $failed = false): void
    {
        DB::transaction(function () use ($execution, $step, $failed): void {
            $execution->refresh();
            $totalSteps = $execution->steps()->count();
            $failedSteps = $execution->steps()->where('status', AutomationStepStatus::FAILED)->count();
            $successSteps = $execution->steps()->where('status', AutomationStepStatus::SUCCESS)->count();

            if ($failed && $failedSteps > 0 && $failedSteps === $totalSteps) {
                $execution->status = AutomationExecutionStatus::FAILED;
                $execution->finished_at = now();
                $execution->save();

                NotifyOnAutomationFailure::dispatch($execution->id);
            } elseif ($failedSteps === 0 && $successSteps === $totalSteps) {
                $execution->status = AutomationExecutionStatus::SUCCESS;
                $execution->finished_at = now();
                $execution->save();
            } elseif ($successSteps > 0) {
                $execution->status = AutomationExecutionStatus::PARTIAL;
                $execution->finished_at = now();
                $execution->save();
            } else {
                // Pending steps remain; leave the execution running.
                if (! in_array($execution->status, [
                    AutomationExecutionStatus::RUNNING,
                    AutomationExecutionStatus::FAILED,
                    AutomationExecutionStatus::CIRCUIT_BROKEN,
                ], true)) {
                    $execution->status = AutomationExecutionStatus::RUNNING;
                    $execution->save();
                }
            }
        });
    }

    public function failed(Throwable $e): void
    {
        // When the queue worker permanently fails the Job (after tries=3),
        // record the error against the step and the execution. This is the
        // Laravel-recommended hook for terminal failure.
        $step = AutomationExecutionStep::query()->find($this->stepId);

        if ($step === null) {
            return;
        }

        $step->status = AutomationStepStatus::FAILED;
        $step->error_class = $e::class;
        $step->error_message = $e->getMessage();
        $step->finished_at = now();
        $step->attempt = $this->tries;
        $step->save();

        $execution = $step->execution()->first();

        if ($execution !== null) {
            $execution->status = AutomationExecutionStatus::FAILED;
            $execution->finished_at = now();
            $execution->error_class = $e::class;
            $execution->error_message = $e->getMessage();
            $execution->save();

            NotifyOnAutomationFailure::dispatch($execution->id);
        }
    }
}