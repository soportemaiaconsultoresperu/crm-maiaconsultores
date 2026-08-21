<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Customer;
use App\Models\User;

final class CustomerDeactivated extends DomainEvent
{
    public function __construct(
        public readonly Customer $customer,
        ?User $actor = null,
    ) {
        parent::__construct($actor?->id);
    }

    public function subjectType(): ?string
    {
        return Customer::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->customer->getKey();
    }

    public function payload(): array
    {
        return [
            'code' => $this->customer->code,
            'owner_id' => $this->customer->owner_id,
            'status' => $this->customer->status,
        ];
    }
}