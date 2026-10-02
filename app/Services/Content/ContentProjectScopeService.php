<?php

namespace App\Services\Content;

use App\Models\Content\ContentProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "which content projects may this user reach".
 *
 * Later surfaces of the module (contents, media, calendars, exports) reuse it:
 * a listing scopes its query through `visibleProjects()` and a record-level
 * check calls `canAccess()`. Keeping both answers here is what makes the rule
 * consistent across those surfaces instead of being re-derived per controller.
 *
 * Membership is the `content_project_user` pivot, and only that pivot. The CRM
 * owner/team scopes (`DataScopeService`) are deliberately NOT consulted: a
 * supervisor or a salesperson does not become a member of a content project
 * because of a team, owner or pipeline relationship somewhere else in the CRM.
 *
 * Archiving and access are different questions. `canAccess()` ignores
 * `archived_at`, so an archived project stays readable to its members, while
 * `visibleProjects()` leaves it out of the default listing. Archiving therefore
 * retires a project from normal work without making its history unreadable.
 */
class ContentProjectScopeService
{
    /**
     * The permission that widens reading to every project (the consolidated
     * cross-project view). Expressed as a permission, never as a role name, so
     * it stays extensible.
     */
    public const VIEW_ALL = 'content_projects.view_all';

    /**
     * The projects the user may list: the ones they are assigned to, or every
     * project when the user holds `content_projects.view_all`.
     *
     * Returns a query builder, not a collection, so paginated listings,
     * filters and exports all apply the scope to the same SQL instead of
     * materialising rows first.
     *
     * @return Builder<ContentProject>
     */
    public function visibleProjects(User $user, bool $includeArchived = false): Builder
    {
        $query = ContentProject::query();

        if (! $this->hasCrossProjectView($user)) {
            $query->whereHas('members', function (Builder $members) use ($user): void {
                $members->whereKey($user->id);
            });
        }

        if (! $includeArchived) {
            $query->active();
        }

        return $query;
    }

    /**
     * Whether the user may reach this specific project: membership, or
     * `content_projects.view_all`.
     *
     * Archiving is intentionally not part of the answer — an archived project
     * remains accessible to its members.
     */
    public function canAccess(User $user, ContentProject $project): bool
    {
        if ($this->hasCrossProjectView($user)) {
            return true;
        }

        return $project->members()->whereKey($user->id)->exists();
    }

    /**
     * True when the user reads across every project.
     */
    public function hasCrossProjectView(User $user): bool
    {
        return $user->can(self::VIEW_ALL);
    }
}
