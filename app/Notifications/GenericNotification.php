<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * B17 — Generic in-app notification used by {@see \App\Jobs\V2\SendOutboundDelivery}
 * for the `database` channel. Placeholder class for v1: the dispatch service
 * passes a flat payload that is persisted as the `data` JSON column on the
 * `notifications` table.
 */
class GenericNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {
    }

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
        return $this->payload;
    }

    public function databaseType(object $notifiable): string
    {
        return (string) ($this->payload['type'] ?? 'generic-notification');
    }
}
