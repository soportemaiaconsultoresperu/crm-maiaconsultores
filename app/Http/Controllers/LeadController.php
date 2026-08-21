<?php

namespace App\Http\Controllers;

use App\Exports\LeadsExport;
use App\Http\Requests\LeadStoreRequest;
use App\Http\Requests\LeadUpdateRequest;
use App\Imports\LeadsImport;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Setting;
use App\Models\Ubigeo;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\LeadDuplicateFinder;
use App\Services\LeadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * B02 Leads UI layer. Every mutation goes through LeadService; validation
 * lives in the FormRequests; authorization in LeadPolicy / permission
 * names. Duplicates are a WARNING with explicit confirmation (ADR-003).
 */
class LeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadDuplicateFinder $duplicates,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Filtered + scoped list (RF-LEAD-009, RF-LEAD-012).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Lead::class);

        $user = $request->user();

        $query = $this->scope->appliesTo(Lead::query(), $user)
            ->with(['owner', 'status', 'source']);

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('doc_number', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        foreach (['status_id', 'source_id', 'interest_level'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->query($column));
            }
        }

        // The owner filter only makes sense for users with cross-scope view.
        if ($request->filled('owner_id')
            && ($user->can('leads.view.any') || $user->can('leads.view.team'))) {
            $query->where('owner_id', $request->query('owner_id'));
        }

        if ($request->query('from')) {
            $query->whereDate('entered_at', '>=', $request->query('from'));
        }

        if ($request->query('to')) {
            $query->whereDate('entered_at', '<=', $request->query('to'));
        }

        $query->orderByDesc('entered_at')->orderByDesc('id');

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');

        $leads = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('leads.index', [
            'leads' => $leads,
            'nextActions' => $this->nextActionsFor($leads->getCollection()->pluck('id')),
            'statuses' => LeadStatus::query()->where('is_active', true)->orderBy('sort')->get(),
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('sort')->get(),
            'owners' => $this->ownerOptions($user),
            'filters' => $request->only(['search', 'status_id', 'source_id', 'owner_id', 'interest_level', 'from', 'to']),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Lead::class);

        return view('leads.create', $this->formContext($request->user()));
    }

    /**
     * Create with the duplicate-confirmation flow (RF-LEAD-006, ADR-003):
     * matches without confirmed_duplicate=1 bounce back with a warning;
     * a confirmed creation logs a 'duplicate-confirmed' audit entry.
     */
    public function store(LeadStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Lead::class);

        $data = $request->validated();
        $duplicates = $this->duplicates->check($data);

        if (! $duplicates->isEmpty() && ! $request->boolean('confirmed_duplicate')) {
            return back()->withInput()->with('duplicates', $this->duplicatePayload($duplicates));
        }

        $lead = $this->leads->create($data, $request->user());

        $this->logDuplicateConfirmation($lead, $request->user(), $duplicates);

        return redirect()
            ->route('leads.show', $lead)
            ->with('status', "Prospecto {$lead->code} creado correctamente.");
    }

    public function edit(Request $request, Lead $lead): View
    {
        Gate::authorize('update', $lead);

        return view('leads.edit', [
            'lead' => $lead->load(['owner', 'status', 'source', 'ubigeo']),
            ...$this->formContext($request->user(), $lead),
        ]);
    }

    /**
     * Update runs the same duplicate flow, ignoring the lead being edited.
     */
    public function update(LeadUpdateRequest $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('update', $lead);

        $data = $request->validated();
        $duplicates = $this->duplicates->check($data, $lead);

        if (! $duplicates->isEmpty() && ! $request->boolean('confirmed_duplicate')) {
            return back()->withInput()->with('duplicates', $this->duplicatePayload($duplicates));
        }

        $this->leads->update($lead, $data, $request->user());

        $this->logDuplicateConfirmation($lead, $request->user(), $duplicates);

        return redirect()
            ->route('leads.show', $lead)
            ->with('status', "Prospecto {$lead->code} actualizado correctamente.");
    }

    /**
     * Detail: full record, merged timeline and next action (RF-LEAD-005,
     * RF-LEAD-010).
     */
        public function show(Lead $lead): View
        {
            Gate::authorize('view', $lead);

            return view('leads.show', [
                'lead' => $lead->load(['owner', 'status', 'source', 'ubigeo']),
                'history' => $this->leads->history($lead),
                'nextAction' => $this->leads->nextAction($lead),
                'activities' => $lead->activities()
                    ->with(['owner', 'type', 'subject'])
                    ->orderByDesc('scheduled_at')
                    ->limit(20)
                    ->get(),
            ]);
        }

    /**
     * Reassign with audit (RF-LEAD-003). Requires leads.assign plus record
     * visibility; the new owner must be inside the actor's scope.
     */
    public function assign(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless($request->user()->can('leads.assign'), 403);
        Gate::authorize('view', $lead);

        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ], [], ['owner_id' => 'responsable']);

        $newOwner = User::query()->findOrFail($validated['owner_id']);

        $visible = $this->scope->visibleOwnerIds($request->user());
        abort_unless($visible === null || in_array((int) $newOwner->id, $visible, true), 403);

        $this->leads->assign($lead, $newOwner, $request->user());

        return redirect()
            ->route('leads.show', $lead)
            ->with('status', "Prospecto {$lead->code} reasignado a {$newOwner->name}.");
    }

    /**
     * POST destroy = deactivation with a mandatory reason (RF-LEAD-011).
     */
    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('delete', $lead);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $this->leads->deactivate($lead, $request->user(), (string) ($validated['reason'] ?? ''));

        return redirect()
            ->route('leads.index')
            ->with('status', "Prospecto {$lead->code} desactivado.");
    }

    /**
         * Import form (RF-LEAD-007).
         */
        public function importForm(Request $request): View
        {
            abort_unless($request->user()->can('leads.import'), 403);

            return view('leads.import');
        }

        /**
         * GET /leads/template — downloads a brand-styled Excel template with two
         * sheets: "Prospectos" (branded headers, dropdown validations, freeze
         * pane, example row) and "Instrucciones" (per-column reference). The
         * columns mirror exactly what LeadsImport + LeadStoreRequest accept,
         * so an operator who fills the template and uploads it gets the same
         * validation feedback as a manual form submit.
         */
        public function template(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
        {
            abort_unless($request->user()->can('leads.import'), 403);

            $filename = 'plantilla-prospectos-' . now()->format('Ymd-His') . '.xlsx';
            $path = storage_path('app/private/imports/' . $filename);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            (new \App\Exports\LeadsTemplateExport())->build()->save($path);

            return response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

    /**
     * Runs the import and shows the summary + row report from ImportResult.
     */
    public function importProcess(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->can('leads.import'), 403);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ], [], ['file' => 'archivo']);

        $import = new LeadsImport($request->user());
        Excel::import($import, $validated['file']);

        return view('leads.import', ['result' => $import->result]);
    }

    /**
     * Export honoring the current list filters (RF-LEAD-008).
     */
    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('leads.export'), 403);

        $filters = $request->only(['search', 'status_id', 'source_id', 'owner_id', 'interest_level', 'from', 'to']);

        return Excel::download(
            new LeadsExport($filters, $request->user()),
            'leads-'.now()->format('Ymd').'.xlsx',
        );
    }

    /**
     * JSON duplicate check for the create/edit form (progressive
     * enhancement; the server-side confirmation flow works without JS).
     */
    public function duplicateCheck(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('leads.create') || $request->user()->can('leads.update'), 403);

        $lead = $request->filled('lead_id') ? Lead::query()->find($request->integer('lead_id')) : null;

        $duplicates = $this->duplicates->check($request->only([
            'doc_number', 'email', 'phone', 'whatsapp',
        ]), $lead);

        return response()->json($this->duplicatePayload($duplicates));
    }

    /**
     * Children of a ubigeo node for the dependent selects (RF-CFG-003).
     */
    public function ubigeoChildren(string $parent): JsonResponse
    {
        $children = Ubigeo::query()
            ->where('parent_code', $parent)
            ->orderBy('name')
            ->get(['code', 'name']);

        return response()->json($children);
    }

    /**
     * Catalogs and scoping data shared by create/edit forms.
     *
     * @return array<string, mixed>
     */
    private function formContext(User $user, ?Lead $lead = null): array
    {
        $departamentos = Ubigeo::query()
            ->where('level', 'departamento')
            ->orderBy('name')
            ->get(['code', 'name']);

        $provincias = collect();
        $distritos = collect();

        // Re-entry after a duplicate warning keeps the submitted ubigeo via
        // old input; edit preselects the stored value.
        $ubigeoCode = old('ubigeo_code') ?? $lead?->ubigeo_code;

        if ($ubigeoCode !== null && $ubigeoCode !== '') {
            $provinciaCode = substr($ubigeoCode, 0, 4).'00';

            $provincias = Ubigeo::query()
                ->where('parent_code', substr($ubigeoCode, 0, 2).'0000')
                ->orderBy('name')
                ->get(['code', 'name']);

            $distritos = Ubigeo::query()
                ->where('parent_code', $provinciaCode)
                ->orderBy('name')
                ->get(['code', 'name']);
        }

        return [
            'statuses' => LeadStatus::query()->where('is_active', true)->orderBy('sort')->get(),
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('sort')->get(),
            'owners' => $this->ownerOptions($user),
            'departamentos' => $departamentos,
            'provincias' => $provincias,
            'distritos' => $distritos,
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

    /**
     * Earliest pending future activity per lead for the current page, in
     * ONE extra query — no N+1 (ADR-012, RNF-DAT-005).
     *
     * @param  Collection<int, int>  $leadIds
     * @return Collection<int, Activity>  keyed by lead id
     */
    private function nextActionsFor(Collection $leadIds): Collection
    {
        if ($leadIds->isEmpty()) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', Lead::class)
            ->whereIntegerInRaw('subject_id', $leadIds)
            ->whereIn('status', ['pending', 'in_process', 'overdue'])
            ->where('scheduled_at', '>', now())
            ->with('type')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $items) => $items->first());
    }

    /**
     * Serializable match lists for the warning block / JSON endpoint.
     *
     * @return array{critical: list<array<string, mixed>>, warning: list<array<string, mixed>>}
     */
    private function duplicatePayload(\App\Support\DuplicateCheckResult $duplicates): array
    {
        $fields = [
            'doc_number_norm' => 'documento',
            'email_norm' => 'correo',
            'phone_norm' => 'teléfono',
            'whatsapp_norm' => 'whatsapp',
        ];

        $map = fn (array $match): array => [
            'code' => $match['code'],
            'full_name' => $match['full_name'],
            'field' => $fields[$match['field']] ?? $match['field'],
        ];

        return [
            'critical' => array_map($map, $duplicates->critical),
            'warning' => array_map($map, $duplicates->warnings),
        ];
    }

    /**
     * ADR-003: the explicit confirmation is audited with the matched codes.
     */
    private function logDuplicateConfirmation(
        Lead $lead,
        User $actor,
        \App\Support\DuplicateCheckResult $duplicates,
    ): void {
        if ($duplicates->isEmpty()) {
            return;
        }

        activity()
            ->performedOn($lead)
            ->causedBy($actor)
            ->event('duplicate-confirmed')
            ->withProperties([
                'critical' => array_column($duplicates->critical, 'code'),
                'warning' => array_column($duplicates->warnings, 'code'),
            ])
            ->log("Creación de {$lead->code} confirmada pese a posibles duplicados");
    }
}
