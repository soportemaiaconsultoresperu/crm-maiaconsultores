<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal notification (RF-NOT-001 / RF-ACT-008): an activity was assigned
 * to a different owner by another user. Database channel only. Spanish
 * copy with a human-readable "due in" hint for the recipient.
 *
 * The "due_in_human" helper is rendered from the scheduled_at relative to
 * now; the scheduler never sends this notification — it is emitted by
 * ActivityService::create() / ActivityService::update() when the owner
 * changes.
 */
class ActivityAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $activityTitle,
        public readonly string $subjectLabel,
        public readonly string $fromUserName,
        public readonly string $dueInHuman,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->activityTitle,
            'subject_label' => $this->subjectLabel,
            'from_user' => $this->fromUserName,
            'due_in_human' => $this->dueInHuman,
            'message' => "{$this->fromUserName} le asignó la actividad \"{$this->activityTitle}\" sobre {$this->subjectLabel} (vence {$this->dueInHuman}).",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'activity-assigned';
    }

    /**
     * Render a relative-time Spanish phrase for the recipient header.
     * Examples: "hoy", "mañana", "en 3 días", "hace 2 horas".
     *
     * Falls back to a date string when the diff is outside the relative
     * window the helper supports.
     */
    public static function dueInHuman(\DateTimeInterface $scheduledAt, ?\DateTimeInterface $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $scheduled = \DateTimeImmutable::createFromInterface($scheduledAt);

        $diffSeconds = $scheduled->getTimestamp() - $now->getTimestamp();

        if ($diffSeconds >= 0) {
            if ($diffSeconds < 60) {
                return 'en menos de un minuto';
            }
            $diffMinutes = (int) floor($diffSeconds / 60);
            if ($diffMinutes < 60) {
                return "en {$diffMinutes} minuto".($diffMinutes === 1 ? '' : 's');
            }
            $diffHours = (int) floor($diffMinutes / 60);
            if ($diffHours < 24) {
                return "en {$diffHours} hora".($diffHours === 1 ? '' : 's');
            }
            $diffDays = (int) floor($diffHours / 24);
            return "en {$diffDays} día".($diffDays === 1 ? '' : 's');
        }

        $absSeconds = -$diffSeconds;
        if ($absSeconds < 60) {
            return 'hace menos de un minuto';
        }
        $absMinutes = (int) floor($absSeconds / 60);
        if ($absMinutes < 60) {
            return "hace {$absMinutes} minuto".($absMinutes === 1 ? '' : 's');
        }
        $absHours = (int) floor($absMinutes / 60);
        if ($absHours < 24) {
            return "hace {$absHours} hora".($absHours === 1 ? '' : 's');
        }
        $absDays = (int) floor($absHours / 24);
        return "hace {$absDays} día".($absDays === 1 ? '' : 's');
    }
}