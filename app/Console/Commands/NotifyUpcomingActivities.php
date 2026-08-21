<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityUpcoming;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Reminder notifications for upcoming activities (RF-ACT-007).
 *
 * Emits one ActivityUpcoming notification per pending activity whose
 * `scheduled_at` is in `(now, now + 24h]`. The owner receives the
 * notification only when their account is active.
 *
 * Dedupe: the notifications table is queried for an existing row for the
 * same owner with the same `scheduled_at_iso` payload key. The
 * `last_run_at.<key>` setting is only used to bound the search query;
 * the actual idempotency is enforced per activity.
 */
class NotifyUpcomingActivities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activities:notify-upcoming {--dry-run : Report counts without inserting notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify the owners of activities that will become due in the next 24 hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours(24);

        $candidates = Activity::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $windowEnd)
            ->whereHas('owner', fn ($q) => $q->where('is_active', true))
            ->with('owner', 'subject')
            ->get();

        $notified = 0;
        $skipped = 0;

        foreach ($candidates as $activity) {
            $owner = $activity->owner;
            if ($owner === null) {
                continue;
            }

            if ($activity->reminder_at !== null && $activity->reminder_at->gt($now)) {
                $skipped++;
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

            $owner->notify(new ActivityUpcoming(
                $activity->title,
                $this->subjectLabel($activity),
                $scheduledIso,
            ));

            $notified++;
        }

        $this->info("Upcoming activity notifications: {$notified} sent, {$skipped} skipped (dry-run: ".($this->option('dry-run') ? 'yes' : 'no').').');

        return self::SUCCESS;
    }

    /**
     * True when an `activity-upcoming` row already exists for this owner
     * with the same scheduled_at_iso payload.
     */
    private function alreadyNotified(User $owner, string $scheduledIso): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-upcoming')
            ->where('data->scheduled_at_iso', $scheduledIso)
            ->exists();
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