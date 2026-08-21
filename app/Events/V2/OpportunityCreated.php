<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Opportunity;
use App\Models\User;

final class OpportunityCreated extends DomainEvent
{
    public function __construct(
        public readonly Opportunity $opportunity,
        ?User $actor = null,
    ) {
        parent::__construct($actor?->id);
    }

    public function subjectType(): ?string
    {
        return Opportunity::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->opportunity->getKey();
    }

    public function payload(): array
    {
        return [
            'code' => $this->opportunity->code,
            'title' => $this->opportunity->title,
            'stage_id' => $this->opportunity->stage_id,
            'owner_id' => $this->opportunity->owner_id,
            'lead_id' => $this->opportunity->lead_id,
            'customer_id' => $this->opportunity->customer_id,
            'estimated_amount' => $this->opportunity->estimated_amount,
            'currency_code' => $this->opportunity->currency_code,
            'priority' => $this->opportunity->priority,
        ];
    }
}