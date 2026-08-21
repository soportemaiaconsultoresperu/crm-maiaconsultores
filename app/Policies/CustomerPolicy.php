<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * @extends ModulePolicy<Customer>
 */
class CustomerPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'customers';
    }

    protected function ownerId($record): ?int
    {
        return $record->owner_id;
    }
}
