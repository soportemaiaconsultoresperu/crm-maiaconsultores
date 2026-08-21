<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationStepStatus;
use App\Jobs\V2\NotifyOnAutomationFailure;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use Illuminate\Console\Command;

/**
 * Periodic reconciliation: find automation executions whose steps have
 * exhausted their retry budget and notify admins via NotifyOnAutomationFailure.
 *
 * Idempotent: when the execution is already terminal (failed), it is left
 * untouched.
 */
class ReconcileFailedAutomationSteps extends Command
{
    protected $signature = 'automation:reconcile-failed-steps {--max-attempt=3}';

    protected $description = 'Notify admins about automation executions whose steps have exhausted retries.';

    public function handle(): int
    {
        $maxAttempt = (int) $this->option('max-attempt');

        $stepIds = AutomationExecutionStep::query()
            ->where('status', AutomationStepStatus::FAILED)
            ->where('attempt', '>=', $maxAttempt)
            ->pluck('execution_id')
            ->unique()
            ->all();

        $executions = AutomationExecution::query()
            ->whereIn('id', $stepIds)
            ->where('status', '!=', AutomationExecutionStatus::FAILED)
            ->get();

        foreach ($executions as $execution) {
            $execution->status = AutomationExecutionStatus::FAILED;
            $execution->finished_at = now();
            $execution->save();

            NotifyOnAutomationFailure::dispatch($execution->id);
        }

        $this->info(sprintf('Reconciled %d permanently failed automation executions.', $executions->count()));

        return self::SUCCESS;
    }
}