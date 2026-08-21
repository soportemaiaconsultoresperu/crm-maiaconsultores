<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal notification (RF-NOT-001): an opportunity was assigned to a
 * new owner by a different user. Spanish copy; database channel only.
 */
class OpportunityAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $fromUserName,
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
            'code' => $this->code,
            'title' => $this->title,
            'from_user' => $this->fromUserName,
            'message' => "{$this->fromUserName} le asignó la oportunidad {$this->code}: {$this->title}.",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'opportunity-assigned';
    }
}
