<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the data-visibility scope for a user (ADR-006).
 *
 * - admin: no restriction (null).
 * - vendedor: only records they own.
 * - supervisor: records owned by members of the teams they supervise.
 *
 * Note: scope is resolved through PERMISSIONS, never role names.
 */
class DataScopeService
{
    /**
     * Return the set of owner user ids whose records the given user may
     * see, or null when there is no restriction (admin / global view).
     *
     * @return array<int, int>|null
     */
    public function visibleOwnerIds(User $user): ?array
    {
        // Unrestricted visibility: any "view.any" module permission.
        if ($user->can('leads.view.any') || $user->can('customers.view.any')
            || $user->can('opportunities.view.any')) {
            return null;
        }

        // Supervisor scope: members of active teams they supervise,
        // including themselves.
        if ($user->can('leads.view.team')) {
            $memberIds = Team::query()
                ->where('supervisor_id', $user->id)
                ->where('is_active', true)
                ->with('members:id')
                ->get()
                ->flatMap(fn (Team $team) => $team->members->pluck('id'))
                ->push($user->id)
                ->unique()
                ->values()
                ->all();

            return array_map('intval', $memberIds);
        }

        // Salesperson scope: own records only.
        return [$user->id];
    }

/**
     * Apply the visibility scope to a query constrained by an owner
     * column (defaults to "owner_id"). Returns the scoped builder.
     *
     * When the queried table does not actually carry the owner column
     * (global catalogs like products) the scope is a no-op: the data
     * is not owner-scoped by design.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function appliesTo(Builder $query, User $user, string $ownerColumn = 'owner_id'): Builder
    {
        $ownerIds = $this->visibleOwnerIds($user);

        if ($ownerIds === null) {
            return $query;
        }

        $model = $query->getModel();
        $table = $model->getTable();
        $hasColumn = Schema::hasColumn($table, $ownerColumn);

        if (! $hasColumn) {
            return $query;
        }

        return $query->whereIn($ownerColumn, $ownerIds);
    }

    /**
     * Apply the visibility scope to a query over the `users` table.
     *
     * The users table does NOT have an `owner_id` column (a user IS the
     * owner of their own records), so the regular appliesTo() with
     * `owner_id` would be a no-op. The semantics are still the same:
     * admin sees everyone, supervisor sees their teams (members +
     * themselves), and vendedor only sees themselves.
     *
     * Used by the admin users index (RF-USR-008) so a supervisor cannot
     * peek at colleagues from another team, while an admin still gets
     * the full directory.
     *
     * @param  Builder<\App\Models\User>  $query
     * @return Builder<\App\Models\User>
     */
    public function appliesToUsers(Builder $query, User $user): Builder
    {
        $ownerIds = $this->visibleOwnerIds($user);

        if ($ownerIds === null) {
            return $query;
        }

        return $query->whereIn('users.id', $ownerIds);
    }
}
