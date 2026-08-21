<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal notification (RF-NOT-001 / RF-ACT-003): an activity became
 * overdue in the current scheduler run. Emitted by the
 * `activities:notify-overdue` scheduler command; idempotent per 24h window
 * (the scheduler keeps the dedupe key as `scheduled_at_iso` in the data
 * payload).
 */
class ActivityOverdue extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $activityTitle,
        public readonly string $subjectLabel,
        public readonly string $scheduledAtIso,
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
            'scheduled_at_iso' => $this->scheduledAtIso,
            'message' => "La actividad \"{$this->activityTitle}\" sobre {$this->subjectLabel} está vencida (estaba programada para {$this->scheduledAtIso}).",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'activity-overdue';
    }
}