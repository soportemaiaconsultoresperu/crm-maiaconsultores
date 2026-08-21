<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Quotation;
use App\Models\User;

final class QuotationAccepted extends DomainEvent
{
    public function __construct(
        public readonly Quotation $quotation,
        ?User $actor = null,
    ) {
        parent::__construct($actor?->id);
    }

    public function subjectType(): ?string
    {
        return Quotation::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->quotation->getKey();
    }

    public function payload(): array
    {
        return [
            'number' => $this->quotation->number,
            'status' => $this->quotation->status,
            'owner_id' => $this->quotation->owner_id,
            'lead_id' => $this->quotation->lead_id,
            'customer_id' => $this->quotation->customer_id,
            'opportunity_id' => $this->quotation->opportunity_id,
            'total' => $this->quotation->total,
            'currency_code' => $this->quotation->currency_code,
            'accepted_at' => optional($this->quotation->accepted_at)->toIso8601String(),
        ];
    }
}