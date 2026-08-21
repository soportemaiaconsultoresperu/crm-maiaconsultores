<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends ModulePolicy<Opportunity>
 */
class OpportunityPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'opportunities';
    }

    /**
     * @param  Opportunity  $record
     */
    protected function ownerId(Model $record): ?int
    {
        return $record->owner_id;
    }
}
