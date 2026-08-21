<?php

namespace App\Policies;

use App\Models\CampaignRun;
use App\Models\User;

class CampaignRunPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'campaigns';
    }

    protected function ownerId(\Illuminate\Database\Eloquent\Model $record): ?int
    {
        /** @var CampaignRun $record */
        return $record->owner_id;
    }
}
