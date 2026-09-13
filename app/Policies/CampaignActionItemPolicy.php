<?php

namespace App\Policies;

use App\Models\CampaignActionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy for individual campaign action items. The "owner" is the item's
 * assigned_to (via participant.assigned_to) when the activity exists;
 * otherwise the run's owner_id. For convenience we expose the run's owner
 * via the activity owner.
 *
 * NAMING (audit A-1): this class used to be `CampaignItemPolicy`, which Laravel's
 * policy auto-discovery never matched — it looks for `{Model}Policy`, and the
 * model is `App\Models\CampaignActionItem`. The class was therefore orphaned:
 * `Gate::getPolicyFor(CampaignActionItem::class)` returned null, so every
 * `Gate::authorize(...)` on an item failed for non-admins. It is named after the
 * model now, which is the mechanism the sibling campaign policies
 * (`CampaignRunPolicy`, `CampaignTemplatePolicy`) and every other non-Courses
 * module in this repository already rely on. No `$policies` entry is needed.
 */
class CampaignActionItemPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'campaigns';
    }

    protected function ownerId(Model $record): ?int
    {
        /** @var CampaignActionItem $record */
        return $record->participant?->assigned_to
            ?? $record->run?->owner_id;
    }

    /**
     * The campaign module's permission vocabulary is flat (`campaigns.view`),
     * not the three-part ADR-006 shape (`campaigns.view.any`) that
     * `ModulePolicy::viewAny` looks for. The inherited implementation could
     * therefore never return true for a non-admin, because the rows it asked for
     * (`campaigns.view.any`, `campaigns.view.team`, `campaigns.view.own`) do not
     * exist. `ModulePolicy` is shared by every module and must not change, so the
     * campaign policies bind `viewAny` to the permission the campaign migration
     * actually creates.
     */
    public function viewAny(User $user): bool
    {
        return $user->can("{$this->module()}.view");
    }

    /**
     * Specialised: an item can be updated by its assigned_to, or by an admin.
     */
    public function markRealized(User $user, CampaignActionItem $record): bool
    {
        return $user->can('campaigns.mark_realized') && $this->ownerId($record) === $user->id
            || $user->can('campaigns.override_completion');
    }

    public function reschedule(User $user, CampaignActionItem $record): bool
    {
        return $user->can('campaigns.reschedule') && $this->ownerId($record) === $user->id
            || $user->can('campaigns.override_completion');
    }

    public function cancel(User $user, CampaignActionItem $record): bool
    {
        return $user->can('campaigns.reschedule') && $this->ownerId($record) === $user->id
            || $user->can('campaigns.override_completion');
    }
}
