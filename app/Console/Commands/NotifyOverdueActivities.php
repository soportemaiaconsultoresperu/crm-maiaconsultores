<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Setting;
use App\Notifications\ActivityOverdue;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Overdue notifications for activities that flipped to `overdue` since
 * the previous run (RF-ACT-003 / RF-NOT-001).
 *
 * Idempotency: per-row dedupe uses the notifications table. The
 * `last_run_at.overdue` setting narrows the search; on the very first
 * run the default cursor is "now - 1 day" so the bootstrap does not
 * flood historical overdue rows from previous B-blocks.
 */
class NotifyOverdueActivities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activities:notify-overdue {--dry-run : Report counts without inserting notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify the owners of activities that became overdue since the last run';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $lastRun = $this->lastRunAt('overdue');

        $candidates = Activity::query()
            ->where('status', 'overdue')
            ->where('scheduled_at', '>', $lastRun)
            ->where('scheduled_at', '<=', $now)
            ->with('owner', 'subject')
            ->get();

        $notified = 0;
        $skipped = 0;

        foreach ($candidates as $activity) {
            $owner = $activity->owner;
            if ($owner === null || ! $owner->is_active) {
                continue;
            }

            $scheduledIso = $activity->scheduled_at->toIso8601String();

            if ($this->alreadyNotified($owner, $scheduledIso)) {
                $skipped++;
                continue;
            }

            if ($this->option('dry-run')) {
                $notified++;
                continue;
            }

            $owner->notify(new ActivityOverdue(
                $activity->title,
                $this->subjectLabel($activity),
                $scheduledIso,
            ));

            $notified++;
        }

        // Always advance the cursor to `now` so the next run only sees
        // rows that became overdue after this run.
        if (! $this->option('dry-run')) {
            $this->storeLastRunAt('overdue', $now);
        }

        $this->info("Overdue activity notifications: {$notified} sent, {$skipped} skipped (dry-run: ".($this->option('dry-run') ? 'yes' : 'no').').');

        return self::SUCCESS;
    }

    /**
     * True when an `activity-overdue` row already exists for this owner
     * with the same scheduled_at_iso payload.
     */
    private function alreadyNotified($owner, string $scheduledIso): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', \App\Models\User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-overdue')
            ->where('data->scheduled_at_iso', $scheduledIso)
            ->exists();
    }

    /**
     * Last run timestamp for the given dedupe key. First run defaults to
     * "now - 1 day" to keep the B05 deploy quiet for historical rows.
     */
    private function lastRunAt(string $key): Carbon
    {
        $value = Setting::query()->where('key', "last_run_at.{$key}")->value('value');

        if ($value === null) {
            return Carbon::now()->subDay();
        }

        return Carbon::parse($value);
    }

    /**
     * Persist the dedupe cursor for the next run.
     */
    private function storeLastRunAt(string $key, Carbon $moment): void
    {
        Setting::query()->updateOrCreate(
            ['key' => "last_run_at.{$key}"],
            ['value' => $moment->toIso8601String(), 'type' => 'string', 'group' => 'activities_scheduler'],
        );
    }

    /**
     * Human-readable subject label (mirrors ActivityService).
     */
    private function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;
        if ($subject === null) {
            return "{$activity->subject_type} #{$activity->subject_id}";
        }

        return match (true) {
            $subject instanceof \App\Models\Lead => "el prospecto {$subject->code}",
            $subject instanceof \App\Models\Customer => "el cliente {$subject->code}",
            $subject instanceof \App\Models\Opportunity => "la oportunidad {$subject->code}",
            default => $subject->getKey() !== null ? "registro #{$subject->getKey()}" : 'registro relacionado',
        };
    }
}