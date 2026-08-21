<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Teams define the data scope (ADR-006). Management is guarded by the
 * teams.manage permission; supervisors may additionally inspect the
 * teams they supervise (leads.view.team holders need to know their team).
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('teams.manage');
    }

    public function view(User $user, Team $team): bool
    {
        return $user->can('teams.manage')
            || $team->supervisor_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('teams.manage');
    }

    public function update(User $user, Team $team): bool
    {
        return $user->can('teams.manage');
    }

    public function delete(User $user, Team $team): bool
    {
        // Teams are deactivated, never deleted, to preserve scope history.
        return false;
    }
}
