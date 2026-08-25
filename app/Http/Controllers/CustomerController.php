<?php

namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\InvoiceStatus;
use App\Models\Setting;
use App\Models\Ubigeo;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\DataScopeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * B03 Customers UI layer (RF-CLI-001..006). Thin controllers: validation in
 * the FormRequests, business logic in CustomerService, authorization in
 * CustomerPolicy / permission names. Mirrors LeadController conventions.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Filtered + scoped list (RF-CLI-003). Filters: search (code/name/doc/
     * email), person_type, owner (only for cross-scope viewers).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Customer::class);

        $user = $request->user();

        $query = $this->scope->appliesTo(Customer::query(), $user)
            ->with(['owner'])
            ->withCount('contacts');

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('legal_name', 'like', $term)
                    ->orWhere('trade_name', 'like', $term)
                    ->orWhere('doc_number', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if ($request->filled('person_type')) {
            $query->where('person_type', $request->query('person_type'));
        }

        // The owner filter only makes sense for users with cross-scope view.
        if ($request->filled('owner_id')
            && ($user->can('customers.view.any') || $user->can('customers.view.team'))) {
            $query->where('owner_id', $request->query('owner_id'));
        }

        $query->orderBy('code');

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');

        $customers = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'nextActions' => $this->nextActionsFor($customers->getCollection()->pluck('id')),
            'owners' => $this->ownerOptions($user),
            'filters' => $request->only(['search', 'person_type', 'owner_id']),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Customer::class);

        return view('customers.create', $this->formContext($request->user()));
    }

    public function store(CustomerStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $customer = $this->customers->create($request->validated(), $request->user());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Cliente {$customer->code} creado correctamente.");
    }

    public function edit(Request $request, Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('customers.edit', [
            'customer' => $customer->load(['owner', 'ubigeo']),
            ...$this->formContext($request->user(), $customer),
        ]);
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $this->customers->update($customer, $request->validated(), $request->user());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Cliente {$customer->code} actualizado correctamente.");
    }

    /**
     * 360° ficha (RF-CLI-004..006): data, contacts, timeline (with the
     * origin lead's history), placeholders for B04/B06 and the conversion
     * source banner when the customer came from a lead.
     */
    public function show(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        $canViewPayments = Gate::allows('customer-payments.view');
        $canManagePayments = Gate::allows('create', [\App\Models\CustomerInvoice::class, $customer]);

        $relations = [
            'owner',
            'ubigeo',
            'convertedFromLead',
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('first_name'),
        ];

        if ($canViewPayments) {
            $relations['invoices'] = fn ($q) => $q->with('status')->orderByDesc('due_date')->orderByDesc('id');
        }

        return view('customers.show', [
            'customer' => $customer->load($relations),
            'history' => $this->customers->history($customer),
            'activities' => $customer->activities()
                ->with(['owner', 'type', 'subject'])
                ->orderByDesc('scheduled_at')
                ->limit(20)
                ->get(),
            'nextAction' => $customer->activities()
                ->whereIn('status', ['pending', 'in_process', 'overdue'])
                ->where('scheduled_at', '>', now())
                ->orderBy('scheduled_at')
                ->first(),
            'canViewPayments' => $canViewPayments,
            'canManagePayments' => $canManagePayments,
            'invoiceStatuses' => $canManagePayments
                ? InvoiceStatus::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get()
                : collect(),
        ]);
    }

    /**
     * POST destroy = deactivation with a mandatory reason (RF-CLI-003).
     */
    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $this->customers->deactivate($customer, $request->user(), (string) ($validated['reason'] ?? ''));

        return redirect()
            ->route('customers.index')
            ->with('status', "Cliente {$customer->code} desactivado.");
    }

    /**
     * Export honoring the current list filters (RF-CLI-003), same pattern
     * as leads-export: the customers.export permission + the actor's data
     * scope is applied inside the export query.
     */
    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('customers.export'), 403);

        $filters = $request->only(['search', 'person_type', 'owner_id']);

        return Excel::download(
            new CustomersExport($filters, $request->user()),
            'clientes-'.now()->format('Ymd').'.xlsx',
        );
    }

/**
     * Catalogs and scoping data shared by create/edit forms.
     *
     * Ubigeo: ALL departamentos, provincias and distritos are loaded up
     * front. The form lets the operator filter any select with a textbox
     * (client-side) and pick freely — the cascade is cosmetic, only
     * `ubigeo_code` (the distrito) is persisted. Null is always available
     * via the "Seleccione" option.
     *
     * @return array<string, mixed>
     */
    private function formContext(User $user, ?Customer $customer = null): array
    {
        $departamentos = Ubigeo::query()
            ->where('level', 'departamento')
            ->orderBy('name')
            ->get(['code', 'name']);

        $provincias = Ubigeo::query()
            ->where('level', 'provincia')
            ->orderBy('name')
            ->get(['code', 'name']);

        $distritos = Ubigeo::query()
            ->where('level', 'distrito')
            ->orderBy('name')
            ->get(['code', 'name']);

        return [
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
     * Earliest pending future activity per customer for the current page, in
     * ONE extra query — same map pattern as LeadController (no N+1).
     *
     * @param  Collection<int, int>  $customerIds
     * @return Collection<int, Activity>  keyed by customer id
     */
    private function nextActionsFor(Collection $customerIds): Collection
    {
        if ($customerIds->isEmpty()) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', Customer::class)
            ->whereIntegerInRaw('subject_id', $customerIds)
            ->whereIn('status', ['pending', 'in_process', 'overdue'])
            ->where('scheduled_at', '>', now())
            ->with('type')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $items) => $items->first());
    }
}
