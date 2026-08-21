<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationCycleBreak;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Detects when an AutomationAction's side-effect has caused the same
 * subject to re-enter an automation within the cycle window (default 30s).
 *
 * The detector is consulted by the listener BEFORE persisting an execution
 * row. When a cycle is detected the listener:
 *   - creates an AutomationCycleBreak row
 *   - marks the new execution as circuit-broken
 *   - skips dispatching the action.
 */
class CycleDetector
{
    /**
     * Window during which re-entry counts as a cycle.
     */
    public const DEFAULT_WINDOW_SECONDS = 30;

    public function __construct(private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS)
    {
    }

    /**
     * Returns true when an AutomationExecution was created for the same
     * (rule, subject) tuple within the cycle window.
     */
    public function isCycling(int $ruleId, string $subjectType, int $subjectId): bool
    {
        $windowStart = Carbon::now()->subSeconds($this->windowSeconds);

        return AutomationExecution::query()
            ->where('rule_id', $ruleId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('created_at', '>=', $windowStart)
            ->exists();
    }

    /**
     * Record the cycle break and mark the execution as circuit-broken in a
     * single transaction.
     */
    public function recordBreak(
        int $ruleId,
        string $subjectType,
        int $subjectId,
        AutomationExecution $execution,
        string $reason,
    ): AutomationCycleBreak {
        return DB::transaction(function () use ($ruleId, $subjectType, $subjectId, $execution, $reason): AutomationCycleBreak {
            $break = AutomationCycleBreak::create([
                'rule_id' => $ruleId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'reason' => $reason,
                'detected_at' => now(),
            ]);

            $execution->status = 'circuit-broken';
            $execution->error_class = 'AutomationCycleDetected';
            $execution->error_message = $reason;
            $execution->finished_at = now();
            $execution->save();

            return $break;
        });
    }

    /**
     * Convenience: returns true when the action type is one whose mutation
     * could re-enter the engine on the same subject. Currently the change
     * stage action and the assign owner action are flagged — most others
     * are leaves.
     */
    public function canMutateSubject(string $actionType): bool
    {
        return in_array($actionType, [
            'change_stage',
            'change_status',
            'assign_owner',
        ], true);
    }
}