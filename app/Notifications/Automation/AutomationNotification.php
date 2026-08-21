<?php

declare(strict_types=1);

namespace App\Notifications\Automation;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database notification emitted by an automation action.
 *
 * Generic helper for the SendNotificationAction. The notifiable is the
 * user_id in payload (or the subject's owner when none).
 */
class AutomationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $level = 'info',
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
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,
            'message' => "{$this->title} — {$this->body}",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'automation-notification';
    }
}