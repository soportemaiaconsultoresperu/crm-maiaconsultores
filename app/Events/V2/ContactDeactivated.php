<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\Contact;
use App\Models\User;

final class ContactDeactivated extends DomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        ?User $actor = null,
    ) {
        parent::__construct($actor?->id);
    }

    public function subjectType(): ?string
    {
        return Contact::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->contact->getKey();
    }

    public function payload(): array
    {
        return [
            'customer_id' => $this->contact->customer_id,
            'was_primary' => (bool) $this->contact->is_primary,
        ];
    }
}