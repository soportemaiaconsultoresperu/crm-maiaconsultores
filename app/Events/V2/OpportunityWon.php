<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Opportunity;
use App\Models\User;

final class OpportunityWon extends DomainEvent
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
            'final_amount' => $this->opportunity->final_amount,
            'currency_code' => $this->opportunity->currency_code,
        ];
    }
}