<?php

namespace App\Policies;

use App\Models\CampaignActionItem;
use App\Models\User;

/**
 * Policy for individual campaign action items. The "owner" is the item's
 * assigned_to (via participant.assigned_to) when the activity exists;
 * otherwise the run's owner_id. For convenience we expose the run's owner
 * via the activity owner.
 */
class CampaignItemPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'campaigns';
    }

    protected function ownerId(\Illuminate\Database\Eloquent\Model $record): ?int
    {
        /** @var CampaignActionItem $record */
        return $record->participant?->assigned_to
            ?? $record->run?->owner_id;
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
