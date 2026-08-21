<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal notification (RF-NOT-001): someone else moved an opportunity
 * owned by the recipient to another pipeline stage. Spanish copy;
 * database channel only.
 */
class OpportunityStageChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $fromStage,
        public readonly string $toStage,
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
            'from_stage' => $this->fromStage,
            'to_stage' => $this->toStage,
            'message' => "La oportunidad {$this->code} ({$this->title}) pasó de la etapa {$this->fromStage} a {$this->toStage}.",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'opportunity-stage-changed';
    }
}
