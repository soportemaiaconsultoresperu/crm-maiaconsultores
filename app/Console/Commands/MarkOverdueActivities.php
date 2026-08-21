<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Marks overdue activities (RF-ACT-003 core).
 *
 * Pending activities whose scheduled_at is in the past transition to
 * "overdue". Scheduled daily at 02:00 America/Lima (routes/console.php);
 * list queries re-derive "due today" from scheduled_at, so this command
 * only persists the state used by dashboards and reports.
 *
 * `--dry-run` reports the count without writing. The companion commands
 * NotifyUpcomingActivities and NotifyOverdueActivities reuse the same
 * idempotency strategy: persist a `last_run_at.<key>` setting so the
 * next run can skip rows that were already notified in the current
 * window.
 */
class MarkOverdueActivities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activities:mark-overdue {--dry-run : Report counts without updating rows}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark pending activities whose scheduled_at is in the past as overdue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Activity::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<', now());

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Activities that would be marked overdue: {$count}");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Activities marked as overdue: 0');

            return self::SUCCESS;
        }

        $updated = Activity::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<', now())
            ->update(['status' => 'overdue']);

        $this->info("Activities marked as overdue: {$updated}");

        return self::SUCCESS;
    }
}