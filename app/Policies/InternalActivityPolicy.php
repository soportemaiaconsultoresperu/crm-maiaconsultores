<?php

namespace App\Policies;

use App\Models\InternalActivity;
use App\Models\User;

/**
 * Any active user may list and create internal activities; visibility of a
 * single one follows its audience; only the creator edits or deletes it.
 * Admins pass through the global Gate::before bypass.
 */
class InternalActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InternalActivity $activity): bool
    {
        return $activity->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, InternalActivity $activity): bool
    {
        return $activity->created_by === $user->id;
    }

    public function delete(User $user, InternalActivity $activity): bool
    {
        return $activity->created_by === $user->id;
    }
}
