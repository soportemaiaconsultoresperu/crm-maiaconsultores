<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * @extends ModulePolicy<Lead>
 */
class LeadPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'leads';
    }

    protected function ownerId($record): ?int
    {
        return $record->owner_id;
    }
}
