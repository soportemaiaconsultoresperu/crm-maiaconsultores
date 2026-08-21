<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Contacts inherit the data scope of their parent customer (ADR-006):
 * a contact is visible to whoever may see the customer.
 */
class ContactPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'contacts';
    }

    /**
     * @param  Contact  $record
     */
    protected function ownerId(Model $record): ?int
    {
        return $record->customer?->owner_id;
    }
}
