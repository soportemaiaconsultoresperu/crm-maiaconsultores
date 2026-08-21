<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidOperationException;
use App\Exports\OpportunitiesExport;
use App\Http\Requests\OpportunityStoreRequest;
use App\Http\Requests\OpportunityUpdateRequest;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\OpportunityService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * B04 Opportunities UI layer (RF-OPP-001..010). Every mutation goes through
 * OpportunityService; stage/win/lose flows have dedicated POST endpoints —
 * won/lost stages are never reachable through the generic stage move.
 */
class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Filtered + scoped list view (RF-OPP-008/010).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Opportunity::class);

        $user = $request->user();

        $query = $this->opportunities->scopeQuery($user)
            ->with(['owner', 'stage', 'lead', 'customer']);

        $this->applyFilters($query, $request, $user);

        $query->orderByDesc('created_at')->orderByDesc('id');

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');
        $opportunities = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('opportunities.index', [
            'opportunities' => $opportunities,
            'nextActions' => $this->opportunities->nextActions($opportunities->getCollection()->pluck('id')->all()),
            'stages' => PipelineStage::query()->where('is_active', true)->orderBy('sort')->get(),
            'currencies' => Currency::query()->where('is_active', true)->get()->keyBy('code'),
            'owners' => $this->ownerOptions($user),
            'filters' => $request->only(['search', 'stage_id', 'owner_id', 'priority', 'status']),
        ]);
    }

    /**
     * Kanban board (RF-OPP-003): one column per active OPEN stage, scoped
     * open opportunities, batched next actions (RF-OPP-004 + ADR-012).
     */
    public function kanban(Request $request): View
    {
        Gate::authorize('viewAny', Opportunity::class);

        $user = $request->user();

        $stages = PipelineStage::query()
            ->where('stage_type', 'open')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        $query = $this->opportunities->scopeQuery($user)
            ->open()
            ->with(['owner', 'stage', 'lead', 'customer']);

        $this->applyFilters($query, $request, $user, ['stage_id', 'status']);

        $opportunities = $query
            ->orderBy('expected_close_at')
            ->orderBy('id')
            ->get();

        return view('opportunities.kanban', [
            'stages' => $stages,
            'opportunitiesByStage' => $opportunities->groupBy('stage_id'),
            'nextActions' => $this->opportunities->nextActions($opportunities->pluck('id')->all()),
            'currencies' => Currency::query()->where('is_active', true)->get()->keyBy('code'),
            'owners' => $this->ownerOptions($user),
            'filters' => $request->only(['search', 'owner_id', 'priority']),
            'total' => $opportunities->count(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Opportunity::class);

        return view('opportunities.create', $this->formContext($request->user()));
    }

    public function store(OpportunityStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Opportunity::class);

        $opportunity = $this->opportunities->create($request->validated(), $request->user());

        return redirect()
            ->route('opportunities.show', $opportunity)
            ->with('status', "Oportunidad {$opportunity->code} creada correctamente.");
    }

    public function edit(Request $request, Opportunity $opportunity): View
    {
        Gate::authorize('update', $opportunity);

        return view('opportunities.edit', [
            'opportunity' => $opportunity->load(['owner', 'stage', 'lead', 'customer', 'contact']),
            ...$this->formContext($request->user()),
        ]);
    }

    public function update(OpportunityUpdateRequest $request, Opportunity $opportunity): RedirectResponse
    {
        Gate::authorize('update', $opportunity);

        try {
            $this->opportunities->update($opportunity, $request->validated(), $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('opportunities.show', $opportunity)
            ->with('status', "Oportunidad {$opportunity->code} actualizada correctamente.");
    }

    /**
     * Detail card + merged stage/activity timeline (RF-OPP-005) + next
     * action; activities and quotations sections are placeholders until
     * B05/B06.
     */
    public function show(Opportunity $opportunity): View
    {
        Gate::authorize('view', $opportunity);

        return view('opportunities.show', [
            'opportunity' => $opportunity->load(['owner', 'stage', 'lead', 'customer', 'contact', 'source', 'lossReason', 'currency']),
            'history' => $this->opportunities->history($opportunity),
            'nextAction' => $this->opportunities->nextAction($opportunity),
            'activities' => $opportunity->activities()
                ->with(['owner', 'type', 'subject'])
                ->orderByDesc('scheduled_at')
                ->limit(20)
                ->get(),
            'lossReasons' => LossReason::query()->where('is_active', true)->orderBy('sort')->get(),
            'currencies' => Currency::query()->where('is_active', true)->get()->keyBy('code'),
        ]);
    }

    /**
     * Generic stage move (RF-OPP-004) used by BOTH the kanban drag&drop and
     * the no-JS "mover a" fallback form. Terminal stages (ganada/perdida)
     * are rejected here: closing requires the explicit win/lose flows
     * (RF-OPP-006/007).
     */
    public function stage(Request $request, Opportunity $opportunity): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $opportunity);

        $validated = $request->validate([
            'stage_id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['stage_id' => 'etapa', 'note' => 'nota']);

        $target = PipelineStage::query()->findOrFail($validated['stage_id']);

        if ($target->stage_type !== 'open') {
            $message = $target->stage_type === 'won'
                ? 'No se puede mover directamente a la etapa Ganada: use el botón «Marcar ganada» e indique el monto final.'
                : 'No se puede mover directamente a la etapa Perdida: use el botón «Marcar perdida» e indique el motivo de pérdida.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        try {
            $this->opportunities->changeStage($opportunity, $target, $request->user(), $validated['note'] ?? null);
        } catch (InvalidOperationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'stage' => $target->name]);
        }

        return back()->with('status', "Oportunidad {$opportunity->code} movida a {$target->name}.");
    }

    /**
     * Win flow (RF-OPP-006): final amount is mandatory and positive; the
     * close date defaults to today in the service.
     */
    public function win(Request $request, Opportunity $opportunity): RedirectResponse
    {
        abort_unless($request->user()->can('opportunities.win'), 403);
        Gate::authorize('update', $opportunity);

        $validated = $request->validate([
            'final_amount' => ['required', 'numeric', 'gt:0'],
            'closed_at' => ['nullable', 'date'],
        ], [
            'final_amount.required' => 'El monto final es obligatorio para marcar la oportunidad como ganada.',
            'final_amount.numeric' => 'El monto final debe ser un número.',
            'final_amount.gt' => 'El monto final debe ser mayor a cero.',
        ], ['final_amount' => 'monto final', 'closed_at' => 'fecha de cierre']);

        try {
            $this->opportunities->markWon($opportunity, $validated, $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('opportunities.show', $opportunity)
            ->with('status', "Oportunidad {$opportunity->code} marcada como ganada.");
    }

    /**
     * Lose flow (RF-OPP-007): a loss reason is mandatory; an optional note
     * lands in the stage history.
     */
    public function lose(Request $request, Opportunity $opportunity): RedirectResponse
    {
        abort_unless($request->user()->can('opportunities.lose'), 403);
        Gate::authorize('update', $opportunity);

        $validated = $request->validate([
            'loss_reason_id' => ['required', 'integer', 'exists:loss_reasons,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'loss_reason_id.required' => 'El motivo de pérdida es obligatorio para marcar la oportunidad como perdida.',
        ], ['loss_reason_id' => 'motivo de pérdida', 'note' => 'nota']);

        try {
            $this->opportunities->markLost($opportunity, (int) $validated['loss_reason_id'], $request->user(), $validated['note'] ?? null);
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('opportunities.show', $opportunity)
            ->with('status', "Oportunidad {$opportunity->code} marcada como perdida.");
    }

    /**
     * POST destroy = soft deactivation with a mandatory reason.
     */
    public function destroy(Request $request, Opportunity $opportunity): RedirectResponse
    {
        Gate::authorize('delete', $opportunity);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $this->opportunities->deactivate($opportunity, $request->user(), $validated['reason']);

        return redirect()
            ->route('opportunities.index')
            ->with('status', "Oportunidad {$opportunity->code} desactivada.");
    }

    /**
     * Export honoring the current list filters (RF-OPP-008), scoped by
     * owner visibility (RF-OPP-010).
     */
    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('opportunities.export'), 403);

        $filters = $request->only(['search', 'stage_id', 'owner_id', 'priority', 'status']);

        $query = (new OpportunitiesExport($filters))->query();
        $this->scope->appliesTo($query, $request->user());

        return Excel::download(
            new OpportunitiesExport($filters, $query),
            'opportunities-'.now()->format('Ymd').'.xlsx',
        );
    }

    /**
     * Shared list/kanban filters. $skip lets the kanban drop filters that do
     * not make sense on the board (its own stage columns / open-only rows).
     *
     * @param  Builder<Opportunity>  $query
     * @param  list<string>  $skip
     */
    private function applyFilters($query, Request $request, User $user, array $skip = []): void
    {
        if (! in_array('stage_id', $skip, true) && $request->filled('stage_id')) {
            $query->where('stage_id', $request->query('stage_id'));
        }

        if (! in_array('status', $skip, true)) {
            $status = (string) $request->query('status');

            if (in_array($status, ['open', 'won', 'lost'], true)) {
                $query->whereHas('stage', fn ($q) => $q->where('stage_type', $status));
            }
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        if ($request->filled('owner_id')
            && ($user->can('opportunities.view.any') || $user->can('opportunities.view.team'))) {
            $query->where('owner_id', $request->query('owner_id'));
        }

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('legal_name', 'like', $term)->orWhere('trade_name', 'like', $term))
                    ->orWhereHas('lead', function ($l) use ($term): void {
                        $l->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('company_name', 'like', $term);
                    });
            });
        }
    }

    /**
     * Catalogs and scoping data shared by create/edit forms.
     *
     * @return array<string, mixed>
     */
    private function formContext(User $user): array
    {
        return [
            'leads' => $this->scope->appliesTo(Lead::query(), $user)
                ->orderByDesc('id')->limit(300)->get(),
            'customers' => $this->scope->appliesTo(Customer::query(), $user)
                ->orderBy('legal_name')->limit(300)->get(),
            'contacts' => Contact::query()
                ->where('is_active', true)
                ->whereHas('customer', fn ($q) => $this->scope->appliesTo($q, $user))
                ->with('customer:id,legal_name')
                ->orderBy('first_name')
                ->limit(500)
                ->get(),
            'stages' => PipelineStage::query()->where('stage_type', 'open')->where('is_active', true)->orderBy('sort')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('sort')->get(),
            'owners' => $this->ownerOptions($user),
        ];
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
}
