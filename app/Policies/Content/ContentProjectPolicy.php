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
 */
class ContentProjectPolicy
{
    public function __construct(
        private readonly ContentProjectScopeService $scope,
    ) {
    }

    /**
     * Opening the module list. Narrowing to the projects the user may actually
     * see is `view()` / the scope resolver the listing query uses.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('content_projects.view')
            || $user->can('content_projects.view_all');
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

    public function update(User $user, ContentProject $project): bool
    {
        return $user->can('content_projects.update')
            && $this->scope->canAccess($user, $project);
    }

    public function archive(User $user, ContentProject $project): bool
    {
        return $user->can('content_projects.archive')
            && $this->scope->canAccess($user, $project);
    }

    public function assignMembers(User $user, ContentProject $project): bool
    {
        return $user->can('content_projects.assign_members')
            && $this->scope->canAccess($user, $project);
    }
}
