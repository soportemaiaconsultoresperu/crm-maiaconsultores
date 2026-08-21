<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Lead;
use App\Models\User;

/**
 * Fired after LeadService::assign() commits. Carries the previous owner id
 * so conditions can react to ownership transitions.
 */
final class LeadAssigned extends DomainEvent
{
    public function __construct(
        public readonly Lead $lead,
        public readonly ?int $previousOwnerId,
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
            'owner_id' => $this->lead->owner_id,
            'previous_owner_id' => $this->previousOwnerId,
        ];
    }
}