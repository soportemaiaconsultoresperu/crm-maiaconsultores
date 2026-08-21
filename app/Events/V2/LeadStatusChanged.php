<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Lead;
use App\Models\User;

/**
 * Fired when a lead's status_id changes. LeadService::update() is the
 * authoritative emitter; the service must detect a status change before
 * saving and emit this event after commit.
 */
final class LeadStatusChanged extends DomainEvent
{
    public function __construct(
        public readonly Lead $lead,
        public readonly ?int $previousStatusId,
        ?User $actor = null,
    ) {
        parent::__construct($actor?->id);
    }

    public function subjectType(): ?string
    {
        return Lead::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->lead->getKey();
    }

    public function payload(): array
    {
        return [
            'code' => $this->lead->code,
            'status_id' => $this->lead->status_id,
            'previous_status_id' => $this->previousStatusId,
            'owner_id' => $this->lead->owner_id,
            'interest_level' => $this->lead->interest_level,
        ];
    }
}