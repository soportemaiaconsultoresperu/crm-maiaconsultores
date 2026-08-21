<?php

namespace App\Policies;

use App\Models\CampaignRun;
use App\Models\User;

/**
 * Global reschedule (reprogramming general) is a higher-impact operation;
 * requires the explicit `campaigns.reschedule` permission plus ownership.
 */
class CampaignReschedulePolicy extends ModulePolicy
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
