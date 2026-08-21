<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationStepStatus;
use App\Jobs\V2\RunAutomationAction;
use App\Models\AutomationExecutionStep;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Recovery command: re-dispatch RunAutomationAction jobs for steps that are
 * still pending after 30s (e.g. when a worker crashed before picking them
 * up).
 */
class DispatchDueAutomationSteps extends Command
{
    protected $signature = 'automation:dispatch-due-steps {--stuck-after=30 : Seconds after which a pending step is considered stuck}';

    protected $description = 'Re-dispatch RunAutomationAction jobs for stuck pending steps.';

    public function handle(): int
    {
        $stuckAfter = (int) $this->option('stuck-after');
        $cutoff = Carbon::now()->subSeconds(max(1, $stuckAfter));

        $steps = AutomationExecutionStep::query()
            ->where('status', AutomationStepStatus::PENDING)
            ->where('queued_at', '<=', $cutoff)
            ->get();

        foreach ($steps as $step) {
            RunAutomationAction::dispatch($step->id);
        }

        $this->info(sprintf('Re-dispatched %d stuck automation steps.', $steps->count()));

        return self::SUCCESS;
    }
}