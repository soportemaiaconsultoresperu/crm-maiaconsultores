<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Opportunity;
use App\Models\User;

final class OpportunityLost extends DomainEvent
{
    public function __construct(
        public readonly Opportunity $opportunity,
        ?User $actor = null,
        public readonly ?string $lossReason = null,
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
            'loss_reason' => $this->lossReason,
            'loss_reason_id' => $this->opportunity->loss_reason_id,
        ];
    }
}