<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Lead;
use App\Models\User;

final class LeadDeactivated extends DomainEvent
{
    public function __construct(
        public readonly Lead $lead,
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
            'owner_id' => $this->lead->owner_id,
            'status_id' => $this->lead->status_id,
        ];
    }
}