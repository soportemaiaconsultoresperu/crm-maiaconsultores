<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\V2\TimeDriven\ActivityOverdue;
use App\Models\Activity;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Scheduler-driven event emitter: scans activities whose scheduled_at is in
 * the past and whose status is still pending/in_process/overdue and emits
 * ActivityOverdue for each.
 */
class EmitActivityOverdue extends Command
{
    protected $signature = 'automation:emit-activity-overdue';

    protected $description = 'Emit ActivityOverdue events for activities past their scheduled_at.';

    public function handle(): int
    {
        $now = Carbon::now();

        $activities = Activity::query()
            ->whereIn('status', ['pending', 'in_process', 'overdue'])
            ->where('scheduled_at', '<', $now)
            ->get();

        foreach ($activities as $activity) {
            $daysOverdue = (int) floor($activity->scheduled_at->diffInDays($now, true));

            event(new ActivityOverdue($activity, $daysOverdue));
        }

        $this->info(sprintf('Emitted ActivityOverdue for %d activities.', $activities->count()));

        return self::SUCCESS;
    }
}