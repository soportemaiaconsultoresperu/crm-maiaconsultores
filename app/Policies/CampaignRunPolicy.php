<?php

namespace App\Policies;

use App\Models\CampaignRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy for campaign runs (audit A-1).
 *
 * The controllers gate the whole run lifecycle with
 * `Gate::authorize('schedule'|'pause'|'start'|'cancel'|'complete'|'duplicate'|'reschedule', $run)`,
 * but none of those abilities existed here — this class inherited nothing but
 * `viewAny/view/create/update/delete` from `ModulePolicy`, so Laravel denied
 * every call and the module was admin-only in practice (only the `Gate::before`
 * role bypass let the admin through).
 *
 * Each ability maps 1:1 to a permission row the campaign migration creates. No
 * ability invents a permission and none needs a new migration.
 *
 * Scope decision: these are PERMISSION-ONLY checks, and the record is not
 * consulted. Campaigns are not one of `RolesAndPermissionsSeeder`'s scoped
 * modules: the migration created a single flat `campaigns.view`, with no
 * `.team`/`.own` variants and no `campaigns.view.any`. `ModulePolicy::withinScope`
 * resolves visibility through `DataScopeService`, which is driven by the
 * unrelated `leads/customers/opportunities.view.*` permissions — so applying it
 * here would authorize a campaign run based on the actor's leads team, not on
 * anything the campaign module defines. `ModulePolicy::create()` is likewise
 * permission-only. Binding these abilities to the permission the module actually
 * owns is therefore the honest, non-widening rule.
 */
class CampaignRunPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'campaigns';
    }

    protected function ownerId(Model $record): ?int
    {
        /** @var CampaignRun $record */
        return $record->owner_id;
    }

    /**
     * See `CampaignActionItemPolicy::viewAny`: the module's flat `campaigns.view`
     * is the row that exists; the three-part ADR-006 names the inherited
     * implementation asks for were never created.
     */
    public function viewAny(User $user): bool
    {
        return $user->can("{$this->module()}.view");
    }

    public function schedule(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.schedule');
    }

    public function pause(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.pause');
    }

    /**
     * `admin.campaign_runs.resume` also gates on this ability: resuming a paused
     * run is starting it again, and the module has no separate `campaigns.resume`.
     */
    public function start(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.start');
    }

    public function cancel(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.cancel');
    }

    public function complete(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.complete');
    }

    public function duplicate(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.duplicate');
    }

    /**
     * Global rescheduling of a run (`admin.campaign_runs.reschedule-all`).
     */
    public function reschedule(User $user, CampaignRun $run): bool
    {
        return $user->can('campaigns.reschedule');
    }
}
