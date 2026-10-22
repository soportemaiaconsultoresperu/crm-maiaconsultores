<?php

namespace App\Http\Controllers\InternalProjects;

use App\Enums\InternalProjects\ProjectStatus;
use App\Enums\InternalProjects\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalProjects\InternalProjectRequest;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\User;
use App\Services\InternalProjects\InternalProjectProgressService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Internal projects dashboard and CRUD (odd/tasks/internal-projects.md, T2).
 * Listing is scoped by InternalProject::visibleTo(); a single project is
 * resolved by the `internalProject` route binding (404 when not visible) and
 * authorized through InternalProjectPolicy.
 */
class InternalProjectController extends Controller
{
    /** Phases offered on create ("Crear fases sugeridas"). */
    public const SUGGESTED_PHASES = ['Análisis', 'Diseño', 'Desarrollo', 'Pruebas', 'Lanzamiento'];

    public function __construct(private readonly InternalProjectProgressService $progress) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', InternalProject::class);

        $status = ProjectStatus::tryFrom((string) $request->query('status'));

        $projects = InternalProject::query()
            ->visibleTo($request->user())
            ->with(['responsible:id,name', 'phases', 'tasks'])
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->orderByRaw("case when status in ('terminado', 'cancelado') then 1 else 0 end")
            ->orderBy('starts_on')
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $summaries = [];
        foreach ($projects as $project) {
            $summaries[$project->id] = [
                'progress' => $this->progress->projectProgress($project),
                'phases' => $this->progress->phasesProgress($project),
                'milestones' => $this->progress->nextMilestones($project),
            ];
        }

        return view('internal-projects.index', [
            'projects' => $projects,
            'summaries' => $summaries,
            'status' => $status?->value,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', InternalProject::class);

        return view('internal-projects.create', $this->formData() + ['project' => null]);
    }

    public function store(InternalProjectRequest $request): RedirectResponse
    {
        Gate::authorize('create', InternalProject::class);

        $user = $request->user();

        $project = DB::transaction(function () use ($request, $user): InternalProject {
            $project = InternalProject::create($this->attributes($request) + [
                'responsible_user_id' => $request->validated('responsible_user_id') ?: $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $this->syncRelations($project, $request, [$user->id]);

            if ($request->boolean('create_default_phases')) {
                foreach (self::SUGGESTED_PHASES as $index => $name) {
                    $project->phases()->create(['name' => $name, 'sort' => $index + 1]);
                }
            }

            return $project;
        });

        return redirect()->route('internal-projects.show', $project)
            ->with('status', 'Proyecto interno creado.');
    }

    public function show(Request $request, InternalProject $internalProject): View
    {
        Gate::authorize('view', $internalProject);

        $project = $internalProject->load([
            'responsible:id,name',
            'creator:id,name',
            'members:id,name',
            'audienceRoles:id,name',
            'audienceUsers:id,name',
            'phases',
            'tasks' => fn ($q) => $q->with('responsible:id,name')->orderBy('sort')->orderBy('starts_on')->orderBy('id'),
        ]);

        $filters = [
            'status' => TaskStatus::tryFrom((string) $request->query('status'))?->value,
            'phase' => $request->integer('phase') ?: null,
            'responsible' => $request->integer('responsible') ?: null,
        ];

        $visibleTasks = $project->tasks->filter(fn (InternalProjectTask $task): bool => ($filters['status'] === null || $task->status->value === $filters['status'])
            && ($filters['phase'] === null || $task->internal_project_phase_id === $filters['phase'])
            && ($filters['responsible'] === null || $task->responsible_user_id === $filters['responsible']));

        $user = $request->user();

        return view('internal-projects.show', [
            'project' => $project,
            'progress' => $this->progress->projectProgress($project),
            'phasesProgress' => $this->progress->phasesProgress($project),
            'tasksByPhase' => $visibleTasks->groupBy('internal_project_phase_id'),
            'filters' => $filters,
            'canManage' => $user->can('update', $project),
            'canWork' => $user->can('manageWork', $project),
            'responsibles' => $project->tasks->pluck('responsible')->filter()->unique('id')->sortBy('name')->values(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(InternalProject $internalProject): View
    {
        Gate::authorize('update', $internalProject);

        $internalProject->load(['members:id', 'audienceRoles:id', 'audienceUsers:id']);

        return view('internal-projects.edit', $this->formData() + ['project' => $internalProject]);
    }

    public function update(InternalProjectRequest $request, InternalProject $internalProject): RedirectResponse
    {
        Gate::authorize('update', $internalProject);

        DB::transaction(function () use ($request, $internalProject): void {
            $internalProject->update($this->attributes($request) + [
                'responsible_user_id' => $request->validated('responsible_user_id') ?: $internalProject->responsible_user_id,
                'updated_by' => $request->user()->id,
            ]);
            $this->syncRelations($internalProject, $request);
        });

        return redirect()->route('internal-projects.show', $internalProject)
            ->with('status', 'Proyecto interno actualizado.');
    }

    public function destroy(Request $request, InternalProject $internalProject): RedirectResponse
    {
        Gate::authorize('delete', $internalProject);

        $internalProject->update(['updated_by' => $request->user()->id]);
        $internalProject->delete();

        return redirect()->route('internal-projects.index')
            ->with('status', 'Proyecto interno eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(InternalProjectRequest $request): array
    {
        return $request->safe()->only(['name', 'code', 'description', 'color', 'starts_on', 'ends_on', 'status', 'audience_type']);
    }

    /**
     * Audience pivots follow the audience type; the team always includes the
     * responsible (and, on create, the creator).
     *
     * @param  list<int>  $extraMembers
     */
    private function syncRelations(InternalProject $project, InternalProjectRequest $request, array $extraMembers = []): void
    {
        $audience = $project->audience_type;

        $project->audienceRoles()->sync($audience === InternalProject::AUDIENCE_ROLES
            ? array_map('intval', $request->validated('role_ids', []))
            : []);
        $project->audienceUsers()->sync($audience === InternalProject::AUDIENCE_USERS
            ? array_map('intval', $request->validated('user_ids', []))
            : []);

        $memberIds = array_map('intval', $request->validated('member_ids') ?? []);
        $memberIds = array_merge($memberIds, $extraMembers, array_filter([$project->responsible_user_id]));

        $project->members()->sync(array_values(array_unique($memberIds)));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
