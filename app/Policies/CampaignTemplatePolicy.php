<?php

namespace App\Policies;

use App\Models\CampaignTemplate;
use App\Models\User;

class CampaignTemplatePolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'campaign_templates';
    }

    protected function ownerId(\Illuminate\Database\Eloquent\Model $record): ?int
    {
        /** @var CampaignTemplate $record */
        return $record->owner_id;
    }
}
