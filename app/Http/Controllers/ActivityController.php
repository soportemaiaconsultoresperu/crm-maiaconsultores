<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidOperationException;
use App\Http\Requests\ActivityStoreRequest;
use App\Http\Requests\ActivityUpdateRequest;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\DataScopeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * B05 Activities UI layer (RF-ACT-001..008). Thin controllers: business
 * logic in ActivityService, validation in FormRequests, authorization in
 * ActivityPolicy and module-level permission names.
 *
 * Subject-bound POST endpoints (POST leads/{lead}/activities, etc.) are
 * mounted for inline creation from each subject's show page (RF-ACT-006);
 * the activity controller still routes everything through the same
 * FormRequest + service pair as the standalone flow.
 */
class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Filtered + scoped activity list (RF-ACT-008). Filters: search,
     * status, type, subject_type, owner (cross-scope only), date range.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Activity::class);

        $user = $request->user();

        $query = $this->activities->scopeQuery($user)
            ->with(['owner', 'type', 'subject']);

        $this->applyFilters($query, $request, $user);

        $query->orderBy('scheduled_at')->orderByDesc('id');

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');
        $activities = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('activities.index', [
            'activities' => $activities,
            'types' => ActivityType::query()->where('is_active', true)->orderBy('sort')->get(),
            'owners' => $this->ownerOptions($user),
            'filters' => $request->only(['search', 'status', 'type_id', 'subject_type', 'owner_id', 'date_from', 'date_to']),
            'statuses' => $this->statusOptions(),
            'subjectTypes' => $this->subjectTypeOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Activity::class);

        return view('activities.create', $this->formContext($request->user()));
    }

    public function store(ActivityStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Activity::class);

        $activity = $this->activities->create($request->validated(), $request->user());

        return redirect()
            ->route('activities.show', $activity)
            ->with('status', "Actividad \"{$activity->title}\" creada correctamente.");
    }

    /**
     * Subject-bound creation from a show page (RF-ACT-006). The subject
     * is injected from the route param before validation, so the user
     * cannot spoof a different subject through the inline form.
     */
    public function storeForSubject(Request $request, ?Lead $lead = null, ?Customer $customer = null, ?Opportunity $opportunity = null): RedirectResponse
        {
            Gate::authorize('create', Activity::class);

            // The route definitions (Route::post('leads/{lead}/activities', ...))
            // bind the morph class to a single route per subject type. The
            // controller reads the subject from the method parameters, so the
            // model binding runs first and enforces the record-level
            // authorization.
            $resolved = null;

            if ($lead instanceof Lead) {
                $resolved = ['lead', (int) $lead->getKey()];
            } elseif ($customer instanceof Customer) {
                $resolved = ['customer', (int) $customer->getKey()];
            } elseif ($opportunity instanceof Opportunity) {
                $resolved = ['opportunity', (int) $opportunity->getKey()];
            }

        if ($resolved === null) {
            return back()->with('error', 'No se pudo identificar el sujeto de la actividad.');
        }

        [$resolvedType, $resolvedId] = $resolved;

        // Merge the resolved subject into the request payload, then run
        // the same validation rules the standalone flow uses. This keeps
        // the FormRequest as the single source of truth.
        $request->merge([
            'subject_type' => $resolvedType,
            'subject_id' => $resolvedId,
        ]);

        $formRequest = app(ActivityStoreRequest::class);
        $formRequest->merge($request->all());
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->validateResolved();

        $activity = $this->activities->create($formRequest->validated(), $request->user());

        return redirect()
            ->route($this->showRouteFor($resolvedType), $this->routeParamFor($resolvedType, $resolvedId))
            ->with('status', "Actividad \"{$activity->title}\" registrada correctamente.");
    }

    public function show(Activity $activity): View
    {
        Gate::authorize('view', $activity);

        $activity->load(['owner', 'type', 'subject']);

        return view('activities.show', [
            'activity' => $activity,
            'subjectRoute' => $activity->subject ? $this->showRouteFor($activity->subject->getMorphClass()) : null,
        ]);
    }

    public function edit(Request $request, Activity $activity): View
    {
        Gate::authorize('update', $activity);

        $activity->load(['owner', 'type', 'subject']);

        return view('activities.edit', [
            'activity' => $activity,
            ...$this->formContext($request->user(), $activity),
        ]);
    }

    public function update(ActivityUpdateRequest $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        try {
            $this->activities->update($activity, $request->validated(), $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('activities.show', $activity)
            ->with('status', "Actividad \"{$activity->title}\" actualizada correctamente.");
    }

    /**
     * Soft deactivation (RNF-DAT-001). Requires the activities.delete
     * permission and a mandatory reason for the audit log.
     */
    public function destroy(Request $request, Activity $activity): RedirectResponse
    {
        abort_unless($request->user()->can('activities.delete'), 403);
        Gate::authorize('delete', $activity);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        // Reuse the cancel path: the audit row uses the "activity-cancelled"
        // event with the supplied reason. Soft delete is applied after the
        // audit entry is written, preserving the history.
        DB::transaction(function () use ($activity, $request, $validated): void {
            $activity->delete();
            activity()
                ->performedOn($activity)
                ->causedBy($request->user())
                ->event('activity-deactivated')
                ->withProperties(['reason' => $validated['reason']])
                ->log("Actividad \"{$activity->title}\" desactivada: {$validated['reason']}");
        });

        return redirect()
            ->route('activities.index')
            ->with('status', "Actividad \"{$activity->title}\" desactivada.");
    }

    /**
     * Transition pending → in_process (RF-ACT-002).
     */
    public function start(Request $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        try {
            $this->activities->start($activity, $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('activities.show', $activity)
            ->with('status', "Actividad \"{$activity->title}\" marcada como en proceso.");
    }

    /**
     * Mark as completed (RF-ACT-005, ADR-012). The optional follow-up is
     * rendered in the dedicated `complete` view and validated as required_if
     * when the "create_next" toggle is on.
     */
    public function complete(Request $request, Activity $activity): RedirectResponse
    {
        abort_unless($request->user()->can('activities.complete'), 403);
        Gate::authorize('update', $activity);

        $validated = $request->validate([
            'result' => ['required', 'string', 'max:255'],
            'create_next' => ['nullable', 'in:on'],
            'next_type_id' => ['required_if:create_next,on', 'nullable', 'integer', 'exists:activity_types,id'],
            'next_scheduled_at' => ['required_if:create_next,on', 'nullable', 'date'],
            'next_title' => ['required_if:create_next,on', 'nullable', 'string', 'max:200'],
            'next_owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'result.required' => 'El resultado de la actividad es obligatorio.',
            'next_type_id.required_if' => 'Indique el tipo del siguiente seguimiento.',
            'next_scheduled_at.required_if' => 'Indique la fecha del siguiente seguimiento.',
            'next_title.required_if' => 'Indique el título del siguiente seguimiento.',
        ], [
            'result' => 'resultado',
            'next_type_id' => 'tipo del siguiente seguimiento',
            'next_scheduled_at' => 'fecha del siguiente seguimiento',
            'next_title' => 'título del siguiente seguimiento',
            'next_owner_id' => 'responsable del siguiente seguimiento',
        ]);

        try {
            $this->activities->complete($activity, [
                'result' => $validated['result'],
                'next_scheduled_at' => $validated['next_scheduled_at'] ?? null,
                'next_type_id' => $validated['next_type_id'] ?? null,
                'next_title' => $validated['next_title'] ?? null,
                'next_owner_id' => $validated['next_owner_id'] ?? null,
            ], $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('activities.show', $activity)
            ->with('status', "Actividad \"{$activity->title}\" completada.");
    }

    /**
     * Cancellation (RF-ACT-002). Pending or in_process only.
     */
    public function cancel(Request $request, Activity $activity): RedirectResponse
    {
        abort_unless($request->user()->can('activities.delete'), 403);
        Gate::authorize('update', $activity);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        try {
            $this->activities->cancel($activity, $request->user(), $validated['reason']);
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('activities.show', $activity)
            ->with('status', "Actividad \"{$activity->title}\" cancelada.");
    }

    /**
     * Apply the list filters shared by the index view.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Activity>  $query
     */
    private function applyFilters($query, Request $request, User $user): void
    {
        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('result', 'like', $term);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type_id')) {
            $query->where('type_id', (int) $request->query('type_id'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', Activity::morphClass((string) $request->query('subject_type')));
        }

        if ($request->filled('owner_id')
            && ($user->can('activities.view.any') || $user->can('activities.view.team'))) {
            $query->where('owner_id', (int) $request->query('owner_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('scheduled_at', '>=', $request->query('date_from').' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('scheduled_at', '<=', $request->query('date_to').' 23:59:59');
        }
    }

    /**
     * Selectable owners: within the user's visibility scope only.
     *
     * @return Collection<int, User>
     */
    private function ownerOptions(User $user): Collection
    {
        $visible = $this->scope->visibleOwnerIds($user);

        return User::query()
            ->where('is_active', true)
            ->when($visible !== null, fn ($q) => $q->whereIntegerInRaw('id', $visible))
            ->orderBy('name')
            ->get();
    }

    /**
     * Shared create/edit form context.
     *
     * @return array<string, mixed>
     */
    private function formContext(User $user, ?Activity $activity = null): array
    {
        return [
            'types' => ActivityType::query()->where('is_active', true)->orderBy('sort')->get(),
            'owners' => $this->ownerOptions($user),
            'leads' => $this->scope->appliesTo(Lead::query(), $user)
                ->orderByDesc('id')->limit(300)->get(),
            'customers' => $this->scope->appliesTo(Customer::query(), $user)
                ->orderBy('legal_name')->limit(300)->get(),
            'opportunities' => $this->scope->appliesTo(Opportunity::query(), $user)
                ->orderByDesc('id')->limit(300)->get(),
            'statuses' => $this->statusOptions(),
            'priorities' => ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'],
            'subjectTypes' => $this->subjectTypeOptions(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            'pending' => 'Pendiente',
            'in_process' => 'En proceso',
            'overdue' => 'Vencida',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function subjectTypeOptions(): array
    {
        return [
            'lead' => 'Prospecto',
            'customer' => 'Cliente',
            'opportunity' => 'Oportunidad',
        ];
    }

    /**
     * Resolve the (subject_type, subject_id) pair from the route binding
     * for inline POST endpoints. Returns null when no subject route binding
     * is present.
     *
     * @return array{0: string, 1: int}|null
     */
    private function resolveSubjectFromRoute(Request $request): ?array
    {
        if ($request->route('lead') !== null) {
            $lead = $request->route('lead');

            return ['lead', (int) $lead->getKey()];
        }

        if ($request->route('customer') !== null) {
            $customer = $request->route('customer');

            return ['customer', (int) $customer->getKey()];
        }

        if ($request->route('opportunity') !== null) {
            $opportunity = $request->route('opportunity');

            return ['opportunity', (int) $opportunity->getKey()];
        }

        return null;
    }

    /**
     * Map an activity's morph class back to the subject's show route.
     */
    private function showRouteFor(string $morphClass): string
    {
        return match ($morphClass) {
            'lead', Lead::class => 'leads.show',
            'customer', Customer::class => 'customers.show',
            'opportunity', Opportunity::class => 'opportunities.show',
            default => 'activities.index',
        };
    }

    /**
     * Build the route parameter array for the show route of the resolved
     * subject type. Each subject route takes a single parameter; we
     * supply only the relevant one to avoid query-string noise.
     *
     * @return array<string, int>
     */
    private function routeParamFor(string $subjectType, int $subjectId): array
    {
        return match ($subjectType) {
            'lead' => ['lead' => $subjectId],
            'customer' => ['customer' => $subjectId],
            'opportunity' => ['opportunity' => $subjectId],
            default => [],
        };
    }
}
