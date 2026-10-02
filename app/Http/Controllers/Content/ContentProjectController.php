<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentProjectRequest;
use App\Http\Requests\Content\SyncContentProjectMembersRequest;
use App\Http\Requests\Content\UpdateContentProjectRequest;
use App\Models\Content\ContentProject;
use App\Models\Setting;
use App\Models\User;
use App\Services\Content\ContentProjectScopeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Content scheduling module — project administration UI (T3).
 *
 * This is the first request path that actually enforces the authorization
 * defined in T2, so the two rules it must never break are:
 *
 * 1. The listing is `ContentProjectScopeService::visibleProjects()` and nothing
 *    else. A member sees their projects; `content_projects.view_all` widens
 *    reading to every project. No membership rule is re-derived here.
 * 2. A project the user may not reach is reported as 404, never 403. A 403 on
 *    `/content/projects/{id}` would confirm that the id exists, which is exactly
 *    what a foreign project must not disclose. So every record-level action
 *    resolves the project through the scope service first, and only then asks
 *    the policy for the module ability it needs — a reachable project the user
 *    lacks permission to mutate still answers 403.
 *
 * Archiving is a lifecycle flag (`archived_at`), not deletion: an archived
 * project stays readable to its members, and only leaves the DEFAULT listing.
 * `?include_archived=1` brings it back into the list without touching its
 * reachability.
 */
class ContentProjectController extends Controller
{
    public function __construct(
        private readonly ContentProjectScopeService $scope,
    ) {
    }

    /**
     * The projects this user may list, paginated. Archived projects are left
     * out unless the user explicitly asks for them.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ContentProject::class);

        $includeArchived = $request->boolean('include_archived');

        $projects = $this->scope
            ->visibleProjects($request->user(), $includeArchived)
            ->withCount('members')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->pageSize())
            ->withQueryString();

        return view('content.projects.index', [
            'projects' => $projects,
            'filters' => ['include_archived' => $includeArchived],
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ContentProject::class);

        return view('content.projects.create', [
            'project' => null,
        ]);
    }

    public function store(StoreContentProjectRequest $request): RedirectResponse
    {
        Gate::authorize('create', ContentProject::class);

        $project = ContentProject::query()->create($request->validated());

        return redirect()
            ->route('content.projects.show', $project)
            ->with('status', 'Proyecto "'.$project->name.'" creado correctamente.');
    }

    public function show(Request $request, ContentProject $contentProject): View
    {
        $this->ensureReachable($request, $contentProject);
        Gate::authorize('view', $contentProject);

        return view('content.projects.show', [
            'project' => $contentProject->load('members'),
            'memberCandidates' => $this->memberCandidates(),
        ]);
    }

    public function edit(Request $request, ContentProject $contentProject): View
    {
        $this->ensureReachable($request, $contentProject);
        Gate::authorize('update', $contentProject);

        return view('content.projects.edit', [
            'project' => $contentProject,
        ]);
    }

    public function update(UpdateContentProjectRequest $request, ContentProject $contentProject): RedirectResponse
    {
        $this->ensureReachable($request, $contentProject);
        Gate::authorize('update', $contentProject);

        $contentProject->update($request->validated());

        return redirect()
            ->route('content.projects.show', $contentProject)
            ->with('status', 'Proyecto "'.$contentProject->name.'" actualizado correctamente.');
    }

    /**
     * Retire the project from normal use without deleting it. The project and
     * its data stay readable; only the default listing stops showing it.
     */
    public function archive(Request $request, ContentProject $contentProject): RedirectResponse
    {
        $this->ensureReachable($request, $contentProject);
        Gate::authorize('archive', $contentProject);

        $contentProject->archive();

        return redirect()
            ->route('content.projects.show', $contentProject)
            ->with('status', 'Proyecto "'.$contentProject->name.'" archivado. Sigue siendo consultable por sus miembros.');
    }

    /**
     * Bring an archived project back into normal use. Reactivation is an
     * edition of the lifecycle, so it asks for the `update` ability rather than
     * the `archive` one.
     */
    public function reactivate(Request $request, ContentProject $contentProject): RedirectResponse
    {
        $this->ensureReachable($request, $contentProject);
        Gate::authorize('update', $contentProject);

        $contentProject->reactivate();

        return redirect()
            ->route('content.projects.show', $contentProject)
            ->with('status', 'Proyecto "'.$contentProject->name.'" reactivado correctamente.');
    }

    /**
     * Replace the project's membership with the submitted list. Removing a
     * member is simply submitting the list without them.
     */
    public function syncMembers(SyncContentProjectMembersRequest $request, ContentProject $contentProject): RedirectResponse
    {
        $this->ensureReachable($request, $contentProject);
        Gate::authorize('assignMembers', $contentProject);

        $contentProject->members()->sync($request->validated('members') ?? []);

        return redirect()
            ->route('content.projects.show', $contentProject)
            ->with('status', 'Miembros del proyecto "'.$contentProject->name.'" actualizados correctamente.');
    }

    /**
     * A project the user may not reach is reported as 404, never 403: a 403
     * would confirm the project exists. Reachability is answered by the shared
     * scope service, never re-derived here.
     */
    private function ensureReachable(Request $request, ContentProject $project): void
    {
        abort_unless($this->scope->canAccess($request->user(), $project), 404);
    }

    /**
     * The users the member form can offer: active users, ordered by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function memberCandidates(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Page size from the shared setting, with the repository default.
     */
    private function pageSize(): int
    {
        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');

        return max(1, $pageSize ?: 25);
    }
}
