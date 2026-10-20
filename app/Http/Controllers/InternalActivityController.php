<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternalActivityRequest;
use App\Models\InternalActivity;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Internal activities CRUD (odd/tasks/internal-activities.md, T1).
 * Listing is scoped by InternalActivity::visibleTo(); single records go
 * through InternalActivityPolicy.
 */
class InternalActivityController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', InternalActivity::class);

        $scope = $request->query('scope') === 'past' ? 'past' : 'upcoming';

        $activities = InternalActivity::query()
            ->visibleTo($request->user())
            ->with(['creator:id,name', 'participants:id,name'])
            ->when($scope === 'upcoming',
                fn ($q) => $q->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at'),
                fn ($q) => $q->where('starts_at', '<', now()->startOfDay())->orderByDesc('starts_at'))
            ->paginate(20)
            ->withQueryString();

        return view('internal-activities.index', compact('activities', 'scope'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', InternalActivity::class);

        return view('internal-activities.create', $this->formData() + [
            'activity' => null,
            'defaultDate' => $request->query('date'),
        ]);
    }

    public function store(InternalActivityRequest $request): RedirectResponse
    {
        Gate::authorize('create', InternalActivity::class);

        $activity = DB::transaction(function () use ($request): InternalActivity {
            $activity = InternalActivity::create($this->attributes($request) + [
                'created_by' => $request->user()->id,
            ]);
            $this->syncRelations($activity, $request);

            return $activity;
        });

        return redirect()->route('internal-activities.show', $activity)
            ->with('success', 'Actividad interna creada.');
    }

    public function show(InternalActivity $internalActivity): View
    {
        Gate::authorize('view', $internalActivity);

        $internalActivity->load(['creator:id,name', 'participants:id,name', 'audienceRoles:id,name', 'audienceUsers:id,name']);

        return view('internal-activities.show', ['activity' => $internalActivity]);
    }

    public function edit(InternalActivity $internalActivity): View
    {
        Gate::authorize('update', $internalActivity);

        $internalActivity->load(['participants:id', 'audienceRoles:id', 'audienceUsers:id']);

        return view('internal-activities.edit', $this->formData() + [
            'activity' => $internalActivity,
            'defaultDate' => null,
        ]);
    }

    public function update(InternalActivityRequest $request, InternalActivity $internalActivity): RedirectResponse
    {
        Gate::authorize('update', $internalActivity);

        DB::transaction(function () use ($request, $internalActivity): void {
            $internalActivity->update($this->attributes($request) + [
                'updated_by' => $request->user()->id,
            ]);
            $this->syncRelations($internalActivity, $request);
        });

        return redirect()->route('internal-activities.show', $internalActivity)
            ->with('success', 'Actividad interna actualizada.');
    }

    public function destroy(InternalActivity $internalActivity): RedirectResponse
    {
        Gate::authorize('delete', $internalActivity);

        $internalActivity->delete();

        return redirect()->route('internal-activities.index')
            ->with('success', 'Actividad interna eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(InternalActivityRequest $request): array
    {
        return $request->safe()->only(['title', 'description', 'location', 'starts_at', 'ends_at', 'audience_type']);
    }

    private function syncRelations(InternalActivity $activity, InternalActivityRequest $request): void
    {
        $audience = $activity->audience_type;

        $activity->audienceRoles()->sync($audience === InternalActivity::AUDIENCE_ROLES
            ? array_map('intval', $request->input('role_ids', []))
            : []);
        $activity->audienceUsers()->sync($audience === InternalActivity::AUDIENCE_USERS
            ? array_map('intval', $request->input('user_ids', []))
            : []);

        $responsibleId = $request->filled('responsible_id') ? (int) $request->input('responsible_id') : null;
        $participantIds = array_map('intval', $request->input('participant_ids', []));
        if ($responsibleId !== null) {
            $participantIds[] = $responsibleId;
        }

        $activity->participants()->sync(collect(array_unique($participantIds))
            ->mapWithKeys(fn (int $id) => [$id => ['is_responsible' => $id === $responsibleId]])
            ->all());
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
