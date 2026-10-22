<?php

namespace App\Console\Commands;

use App\Models\InternalActivity;
use App\Models\User;
use App\Notifications\InternalActivityNotice;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Reminders for internal activities (odd/tasks/internal-activities.md, T3).
 *
 * Every occurrence (recurring series included) starting in (now, now + 24h]
 * notifies each active participant once. Dedupe is per participant, activity
 * and occurrence start, so running the command hourly never repeats a
 * reminder.
 */
class NotifyUpcomingInternalActivities extends Command
{
    protected $signature = 'internal-activities:notify-upcoming';

    protected $description = 'Remind participants of internal activities starting in the next 24 hours';

    public function handle(): int
    {
        $now = Carbon::now()->toImmutable();
        $windowStart = $now->addSecond();
        $windowEnd = $now->addHours(24);
        $sent = 0;

        $activities = InternalActivity::query()
            ->occurringBetween($windowStart, $windowEnd)
            ->with(['participants' => fn ($q) => $q->where('is_active', true)])
            ->get();

        foreach ($activities as $activity) {
            foreach ($activity->occurrencesBetween($windowStart, $windowEnd) as $occurrence) {
                $startsAtIso = $occurrence->toIso8601String();

                foreach ($activity->participants as $participant) {
                    if ($this->alreadyNotified($participant, $activity->id, $startsAtIso)) {
                        continue;
                    }

                    $participant->notify(new InternalActivityNotice(
                        InternalActivityNotice::UPCOMING,
                        $activity->id,
                        $activity->title,
                        $startsAtIso,
                    ));
                    $sent++;
                }
            }
        }

        $this->info("Internal activity reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    private function alreadyNotified(User $user, int $activityId, string $startsAtIso): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', 'internal-activity-'.InternalActivityNotice::UPCOMING)
            ->where('data->internal_activity_id', $activityId)
            ->where('data->starts_at_iso', $startsAtIso)
            ->exists();
    }
}
