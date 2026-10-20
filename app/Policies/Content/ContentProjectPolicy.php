<?php

namespace App\Policies\Content;

use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentProjectScopeService;

/**
 * Authorization for the content scheduling module (task T2).
 *
 * Placement is not cosmetic: Laravel's default discovery guesses policy names
 * by replacing the `Models` namespace segment with `Policies`, so
 * `App\Policies\Content\ContentProjectPolicy` is what `Gate::getPolicyFor()`
 * resolves for `App\Models\Content\ContentProject`. The class is not listed in
 * `AuthServiceProvider::$policies`; `ContentProjectAuthorizationTest` pins the
 * runtime resolution so a later move cannot silently break it.
 *
 * Deny by default: every ability below requires either the module permission or
 * an explicit project membership. Nothing here reads a ROLE name, and nothing
 * here reuses the CRM owner/team scopes — content-project membership is the
 * membership pivot defined in `ContentProjectScopeService`.
 *
 * Two families of writes (review fixes S1/S2):
 *
 *   - working on content (`update`, `trash`): any member of the project, or a
 *     holder of `content_projects.update` who can reach it;
 *   - configuring the project (`configure`, `assignMembers`, `archive`): the
 *     administrative `content_projects.*` permissions only.
 *
 * An archived project is read-only: every write except the lifecycle ability
 * `archive` (which owns reactivation) is refused. The administrator's global
 * `Gate::before` bypass defers to this policy for archived projects
 * (see `AuthServiceProvider`), so the read-only rule binds the admin too.
 */
class ContentProjectPolicy
{
    public function __construct(
        private readonly ContentProjectScopeService $scope,
    ) {
    }

    /**
     * Opening the module list. Narrowing to the projects the user may actually
     * see is `view()` / the scope resolver the listing query uses. Being a
     * member of at least one project (archived or inactive included) is enough to see the
     * module, so members reach it without any module permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('content_projects.view')
            || $user->can('content_projects.view_all')
            || $this->scope->visibleProjects($user, includeArchived: true, includeInactive: true)->exists();
    }

    /**
     * Reading one project: membership, or `content_projects.view_all`.
     *
     * `content_projects.view` alone does NOT open a foreign project — that
     * permission only reaches `viewAny()`. Archived projects stay readable, so
     * `archived_at` is deliberately not consulted here.
     */
    public function view(User $user, ContentProject $project): bool
    {
        return $this->scope->canAccess($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->can('content_projects.create');
    }

    /**
     * Working on the project's content: contents, ideas, attachments and
     * calendar annotations. Membership alone is enough; the module permission
     * covers reachable non-members (e.g. a cross-project editor).
     */
    public function update(User $user, ContentProject $project): bool
    {
        if ($project->isArchived()) {
            return false;
        }

        if ($this->isMember($user, $project)) {
            return true;
        }

        return $user->can('content_projects.update')
            && $this->scope->canAccess($user, $project);
    }

    /**
     * Trashing, restoring, purging and emptying the trash. Same rule as
     * `update`: members may manage their project's trash.
     */
    public function trash(User $user, ContentProject $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Configuring the project: catalogs, custom fields, sections, options and
     * the project's own settings. Administrative only; membership is not enough.
     */
    public function configure(User $user, ContentProject $project): bool
    {
        return ! $project->isArchived()
            && $user->can('content_projects.update')
            && $this->scope->canAccess($user, $project);
    }

    /**
     * Archiving and reactivating. Deliberately NOT blocked by `archived_at`:
     * this ability is what brings an archived project back.
     */
    public function archive(User $user, ContentProject $project): bool
    {
        return $user->can('content_projects.archive')
            && $this->scope->canAccess($user, $project);
    }

    public function assignMembers(User $user, ContentProject $project): bool
    {
        return ! $project->isArchived()
            && $user->can('content_projects.assign_members')
            && $this->scope->canAccess($user, $project);
    }

    private function isMember(User $user, ContentProject $project): bool
    {
        return $project->members()->whereKey($user->id)->exists();
    }
}
