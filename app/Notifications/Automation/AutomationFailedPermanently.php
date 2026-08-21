<?php

declare(strict_types=1);

namespace App\Notifications\Automation;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal notification sent to admins when an automation execution has
 * exhausted its retry budget. Database channel only (matches existing
 * notifications pattern in V1).
 */
class AutomationFailedPermanently extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $executionId,
        public readonly string $ruleName,
        public readonly string $triggerEvent,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?string $errorClass,
        public readonly ?string $errorMessage,
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
            'execution_id' => $this->executionId,
            'rule_name' => $this->ruleName,
            'trigger_event' => $this->triggerEvent,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
            'message' => sprintf(
                'La automatización "%s" (%s) terminó en fallo permanente sobre %s #%d.',
                $this->ruleName,
                $this->triggerEvent,
                class_basename($this->subjectType),
                $this->subjectId,
            ),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'automation-failed-permanently';
    }
}