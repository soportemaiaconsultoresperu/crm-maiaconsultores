<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamStoreRequest;
use App\Http\Requests\TeamUpdateRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Admin teams UI (B08 / RF-USR-004 follow-up, ADR-006).
 *
 * Teams define the data scope; this controller exposes create / edit /
 * membership actions. Membership adds/removes are POST endpoints so each
 * call generates its own audit row (TeamService owns the audit writing).
 * Team deactivation is the only delete-style action because teams are
 * never physically deleted (RF-CFG-002 spirit).
 */
class TeamController extends Controller
{
    public function __construct(
        private readonly TeamService $teams,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Team::class);

        $query = Team::query()
            ->with(['supervisor:id,name', 'members:id']);

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\\%', $search).'%';
            $query->where('name', 'like', $term);
        }

        if ($request->filled('supervisor_id')) {
            $query->where('supervisor_id', (int) $request->query('supervisor_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        $teams = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.teams.index', [
            'teams' => $teams,
            'supervisors' => $this->supervisorCandidates(),
            'filters' => $request->only(['search', 'supervisor_id', 'is_active']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Team::class);

        return view('admin.teams.create', [
            'team' => new Team(['is_active' => true]),
            'supervisors' => $this->supervisorCandidates(),
            'memberCandidates' => $this->memberCandidates(),
        ]);
    }

    public function store(TeamStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Team::class);

        $team = $this->teams->create($request->validated(), $request->user());

        return redirect()
            ->route('admin.teams.edit', $team)
            ->with('status', 'Equipo creado correctamente.');
    }

    public function show(Team $team): View
    {
        Gate::authorize('view', $team);

        return view('admin.teams.show', [
            'team' => $team->load(['supervisor:id,name', 'members:id,name,email']),
            'memberCandidates' => $this->memberCandidates(),
        ]);
    }

    public function edit(Team $team): View
    {
        Gate::authorize('update', $team);

        return view('admin.teams.edit', [
            'team' => $team->load(['supervisor:id,name', 'members:id,name,email']),
            'supervisors' => $this->supervisorCandidates(),
            'memberCandidates' => $this->memberCandidates(),
        ]);
    }

    public function update(TeamUpdateRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $this->teams->update($team, $request->validated(), $request->user());

        return redirect()
            ->route('admin.teams.edit', $team)
            ->with('status', 'Equipo actualizado correctamente.');
    }

    /**
     * Deactivate endpoint. Teams are never deleted (RF-CFG-002 spirit);
     * deactivate flips is_active and audits the reason via TeamService.
     */
    public function destroy(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->teams->deactivate($team, $request->user(), (string) $request->input('reason'));

        return redirect()
            ->route('admin.teams.index')
            ->with('status', 'Equipo desactivado correctamente.');
    }

    public function addMember(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $member = User::query()->findOrFail((int) $request->input('user_id'));
        $this->teams->addMember($team, $member, $request->user());

        return redirect()
            ->route('admin.teams.edit', $team)
            ->with('status', 'Miembro agregado correctamente.');
    }

    public function removeMember(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('update', $team);

        try {
            $this->teams->removeMember($team, $user, $request->user());
        } catch (\App\Exceptions\InvalidOperationException $e) {
            return redirect()
                ->route('admin.teams.edit', $team)
                ->withErrors(['member' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.teams.edit', $team)
            ->with('status', 'Miembro removido correctamente.');
    }

    public function setSupervisor(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('update', $team);

        $this->teams->setSupervisor($team, $user, $request->user());

        return redirect()
            ->route('admin.teams.edit', $team)
            ->with('status', 'Supervisor del equipo actualizado.');
    }

    /**
     * Active users with the supervisor role (the controller does not gate
     * by role names; it relies on teams.manage permission and uses role
     * names purely to shortlist the form).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function supervisorCandidates(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'supervisor']))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Active salespeople available as team members. Excludes the current
     * supervisor so the form does not suggest invalid choices.
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
}