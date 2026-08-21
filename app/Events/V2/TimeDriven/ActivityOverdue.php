<?php

declare(strict_types=1);

namespace App\Events\V2\TimeDriven;

use App\Contracts\Automation\AutomationTriggerEvent;
use App\Models\Activity;

/**
 * Scheduler-driven event emitted by automation:emit-activity-overdue.
 */
final class ActivityOverdue implements AutomationTriggerEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly Activity $activity,
        public readonly int $daysOverdue,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function subjectType(): ?string
    {
        return Activity::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->activity->getKey();
    }

    public function actorId(): ?int
    {
        return null;
    }

    public function payload(): array
    {
        return [
            'title' => $this->activity->title,
            'status' => $this->activity->status,
            'priority' => $this->activity->priority,
            'owner_id' => $this->activity->owner_id,
            'subject_type' => $this->activity->subject_type,
            'subject_id' => $this->activity->subject_id,
            'type_id' => $this->activity->type_id,
            'scheduled_at' => optional($this->activity->scheduled_at)->toIso8601String(),
            'days_overdue' => $this->daysOverdue,
        ];
    }

    public function occurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }
}