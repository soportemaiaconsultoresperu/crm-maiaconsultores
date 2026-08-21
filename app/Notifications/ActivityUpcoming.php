<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal notification (RF-NOT-001 / RF-ACT-007): a PENDING activity is
 * coming up within the next 24 hours. Emitted by the
 * `activities:notify-upcoming` scheduler command, never by the service
 * layer (the service only handles "assigned" noise).
 *
 * The "dedupe" key lives in the notification data (`reminder_at_iso`) so
 * the scheduler can skip a row that already received an upcoming notice in
 * the current 24h window without consulting a separate settings table.
 */
class ActivityUpcoming extends Notification
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
            'message' => "La actividad \"{$this->activityTitle}\" sobre {$this->subjectLabel} está próxima (programada para {$this->scheduledAtIso}).",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'activity-upcoming';
    }
}