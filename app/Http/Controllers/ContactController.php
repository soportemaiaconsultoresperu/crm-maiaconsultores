<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactStoreRequest;
use App\Http\Requests\ContactUpdateRequest;
use App\Imports\ContactsImport;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\ContactService;
use App\Services\DataScopeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * B03 Contacts UI layer (RF-CON-001..003). Contacts always live inside
 * their customer's ficha: every operation authorizes against the customer
 * context and redirects back to customers.show with a flash message.
 */
class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Standalone contacts list. Visibility mirrors the customers module
     * (ADR-006): only contacts whose customer's owner is inside the user's
     * resolved data scope (DataScopeService::visibleOwnerIds). Filters:
     * search (name/email/phone) + optional customer_id.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Contact::class);

        $user = $request->user();

        $query = Contact::query()->with(['customer']);

        // Same "visible customers" scoping used by CustomerController::index,
        // applied to the parent customer of each contact.
        $visible = $this->scope->visibleOwnerIds($user);

        if ($visible !== null) {
            $query->whereHas(
                'customer',
                fn ($q) => $q->whereIntegerInRaw('owner_id', $visible)
            );
        }

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('whatsapp', 'like', $term);
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }

        $query->orderByDesc('is_primary')->orderBy('first_name')->orderBy('last_name');

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');

        $contacts = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('contacts.index', [
            'contacts' => $contacts,
            'filters' => $request->only(['search', 'customer_id']),
        ]);
    }

    /**
     * Excel import form + template download entry point (mirrors the leads
     * import pattern, RF-LEAD-007).
     */
    public function importForm(Request $request): View
    {
        abort_unless($request->user()->can('contacts.create'), 403);

        return view('contacts.import');
    }

    /**
     * Runs the contacts import and shows the summary + row report.
     */
    public function importProcess(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->can('contacts.create'), 403);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ], [], ['file' => 'archivo']);

        $import = new ContactsImport($request->user());
        Excel::import($import, $validated['file']);

        return view('contacts.import', ['result' => $import->result]);
    }

    /**
     * Excel template download for the contacts import.
     */
    public function importTemplate(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('contacts.create'), 403);

        return Excel::download(
            new \App\Exports\ArrayExport(
                [
                    'customer_doc_number', 'first_name', 'last_name', 'position',
                    'area', 'phone', 'whatsapp', 'email', 'is_primary', 'observations',
                ],
                [
                    [
                        '20512345678', 'María', 'Torres Vargas', 'Gerente de Compras',
                        'Compras', '+51 987 654 321', '987654321', 'maria.torres@empresa.com',
                        'si', 'Contacto principal de compras',
                    ],
                ],
            ),
            'contactos-plantilla.xlsx',
        );
    }

        /**
         * Standalone create form. The customer select only offers the
         * customers visible to the actor (same scoping as index, ADR-006).
         */
        public function create(Request $request): View
        {
            abort_unless($request->user()->can('contacts.create'), 403);

            $user = $request->user();

            // Same "visible customers" scoping used by index / CustomerController.
            $visible = $this->scope->visibleOwnerIds($user);

            $query = Customer::query();

            if ($visible !== null) {
                $query->whereIntegerInRaw('owner_id', $visible);
            }

            $customers = $query->orderBy('legal_name')->get();

            return view('contacts.create', ['customers' => $customers]);
        }

        /**
         * Standalone create (contacts.store): unlike the in-ficha store(),
         * the customer comes from the validated customer_id, and visibility
         * is enforced through CustomerPolicy::view (ADR-006).
         */
        public function storeStandalone(ContactStoreRequest $request): RedirectResponse
        {
            abort_unless($request->user()->can('contacts.create'), 403);

            $customer = Customer::query()->findOrFail($request->validated('customer_id'));
            Gate::authorize('view', $customer);

            $contact = $this->contacts->create($customer, $request->validated(), $request->user());

            return redirect()
                ->route('contacts.index')
                ->with('status', "Contacto {$contact->first_name} {$contact->last_name} agregado correctamente.");
        }

        /**
         * Create a contact from the customer's ficha. Primariness (when
         * requested) is guaranteed transactionally by the service
         * (RF-CON-002).
         */
        public function store(ContactStoreRequest $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('view', $customer);
        abort_unless($request->user()->can('contacts.create'), 403);

        $data = $request->validated();
        // The route owns the customer context; the request never trusts it.
        $data['customer_id'] = $customer->id;

        $contact = $this->contacts->create($customer, $data, $request->user());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Contacto {$contact->first_name} {$contact->last_name} agregado correctamente.");
    }

    public function update(ContactUpdateRequest $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $this->contacts->update($contact, $request->validated(), $request->user());

        return redirect()
            ->route('customers.show', $contact->customer)
            ->with('status', "Contacto {$contact->first_name} {$contact->last_name} actualizado correctamente.");
    }

    /**
     * Explicit primary reassignment (RF-CON-002): the previous active
     * primary is unset inside the same transaction.
     */
    public function setPrimary(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($request->user()->can('contacts.update'), 403);
        Gate::authorize('view', $contact);

        $this->contacts->setPrimary($contact, $request->user());

        return redirect()
            ->route('customers.show', $contact->customer)
            ->with('status', "Contacto {$contact->first_name} {$contact->last_name} es ahora el contacto principal.");
    }

    /**
     * POST destroy = deactivation with a mandatory reason (RF-CON-003).
     * Deactivating the primary leaves the customer without a primary —
     * never auto-promotes.
     */
    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $customer = $contact->customer;

        $this->contacts->deactivate($contact, $request->user(), $validated['reason']);

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Contacto {$contact->first_name} {$contact->last_name} desactivado.");
    }
}
