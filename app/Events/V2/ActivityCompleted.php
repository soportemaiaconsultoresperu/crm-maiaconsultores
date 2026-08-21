<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Activity;
use App\Models\User;

final class ActivityCompleted extends DomainEvent
{
    public function __construct(
        public readonly Activity $activity,
        ?User $actor = null,
    ) {
        parent::__construct($actor?->id);
    }

    public function subjectType(): ?string
    {
        return Activity::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->activity->getKey();
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
            'executed_at' => optional($this->activity->executed_at)->toIso8601String(),
        ];
    }
}