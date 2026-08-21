<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;

/**
 * Fired after LeadConversionService::convert() commits.
 *
 * The subject is the LEAD (the conversion's origin). The customer is
 * accessible through the public readonly $customer property so condition
 * evaluators that want to read customer fields can still do so.
 */
final class LeadConverted extends DomainEvent
{
    public function __construct(
        public readonly Lead $lead,
        public readonly Customer $customer,
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
            'lead_code' => $this->lead->code,
            'customer_code' => $this->customer->code,
            'customer_id' => $this->customer->id,
            'owner_id' => $this->lead->owner_id,
            'status_id' => $this->lead->status_id,
        ];
    }
}