<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Lead;
use App\Models\User;

/**
 * Fired after LeadService::create() commits.
 */
final class LeadCreated extends DomainEvent
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
            'person_type' => $this->lead->person_type,
            'interest_level' => $this->lead->interest_level,
            'status_id' => $this->lead->status_id,
            'owner_id' => $this->lead->owner_id,
            'source_id' => $this->lead->source_id,
            'ubigeo_code' => $this->lead->ubigeo_code,
            'email' => $this->lead->email,
            'phone' => $this->lead->phone,
            'company_name' => $this->lead->company_name,
        ];
    }
}