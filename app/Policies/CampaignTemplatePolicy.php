<?php

namespace App\Policies;

use App\Models\CampaignTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy for campaign templates (audit A-1).
 *
 * `CampaignTemplateController::duplicate()` calls
 * `Gate::authorize('duplicate', $template)`, but this class only inherited
 * `viewAny/view/create/update/delete` from `ModulePolicy`, so the duplicate
 * surface was admin-only. `campaign_templates.duplicate` already exists as a
 * permission row.
 *
 * Scope decision: permission-only, same reasoning as `CampaignRunPolicy` —
 * `campaign_templates` has no `.team`/`.own` scope rows and is not one of the
 * seeder's scoped modules.
 */
class CampaignTemplatePolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'campaign_templates';
    }

    protected function ownerId(Model $record): ?int
    {
        /** @var CampaignTemplate $record */
        return $record->owner_id;
    }

    /**
     * See `CampaignActionItemPolicy::viewAny` / `CampaignRunPolicy::viewAny`:
     * the module's flat `campaign_templates.view` is the row that exists, while
     * the inherited implementation asks for `campaign_templates.view.any`, which
     * was never created.
     */
    public function viewAny(User $user): bool
    {
        return $user->can("{$this->module()}.view");
    }

    public function duplicate(User $user, CampaignTemplate $template): bool
    {
        return $user->can('campaign_templates.duplicate');
    }
}
