<?php

namespace App\Policies\InternalProjects;

use App\Models\InternalProjects\InternalProject;
use App\Models\User;

/**
 * "Equipo edita, otros solo ven" (S5). Any active user may list and create
 * projects; viewing follows the audience; the team (members + responsible)
 * works on phases, tasks and statuses (`manageWork`); only the responsible
 * edits settings, members and audience, and deletes (`update`, `delete`).
 * Admins pass through the global Gate::before bypass.
 */
class InternalProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InternalProject $project): bool
    {
        return $project->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, InternalProject $project): bool
    {
        return $project->isManageableBy($user);
    }

    public function delete(User $user, InternalProject $project): bool
    {
        return $project->isManageableBy($user);
    }

    /**
     * Create and update phases, tasks and task statuses.
     */
    public function manageWork(User $user, InternalProject $project): bool
    {
        return $project->isEditableBy($user);
    }
}
