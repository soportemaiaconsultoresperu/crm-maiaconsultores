<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidOperationException;
use App\Exports\QuotationsExport;
use App\Http\Requests\QuotationStoreRequest;
use App\Http\Requests\QuotationUpdateRequest;
use App\Jobs\V2\SendEmailMessage;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Email\EmailAttachment;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\IntegrationAccount;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\DemoData\DemoDataGuard;
use App\Services\OpportunityService;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * B06 Quotation UI layer (RF-COT-001..011). Thin controller: validation in
 * FormRequests, business logic in QuotationService, authorization in
 * QuotationPolicy. Owner-scoped data scope is applied in the listing and
 * the export (ADR-006).
 *
 * Acceptance flow (ADR-007, RF-COT-007/008): the controller collects the
 * explicit opportunity-won confirmation separately from the service-level
 * accept() — the service only mutates the quotation; OpportunityService::
 * markWon is invoked here with final_amount = quotation.total and
 * closed_at = now().
 */
class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly OpportunityService $opportunities,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Filtered + scoped list (RF-COT-008/011). Filters: search, status,
     * owner, customer, lead, opportunity, currency, issued_at range.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Quotation::class);

        $user = $request->user();

        $query = $this->quotations->exportQuery($user, $request->only([
            'search', 'status', 'owner_id', 'customer_id', 'lead_id', 'opportunity_id', 'currency_code', 'issued_at_from', 'issued_at_to',
        ]));

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');
        $quotations = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('quotations.index', [
            'quotations' => $quotations,
            'owners' => $this->ownerOptions($user),
            'customers' => $this->scope->appliesTo(Customer::query(), $user)->orderBy('legal_name')->limit(300)->get(),
            'leads' => $this->scope->appliesTo(Lead::query(), $user)->orderByDesc('id')->limit(300)->get(),
            'opportunities' => $this->scope->appliesTo(Opportunity::query(), $user)->orderByDesc('id')->limit(300)->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'filters' => $request->only([
                'search', 'status', 'owner_id', 'customer_id', 'lead_id', 'opportunity_id',
                'currency_code', 'issued_at_from', 'issued_at_to',
            ]),
        ]);
    }

    /**
     * Stand-alone create form (RF-COT-001).
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', Quotation::class);

        return view('quotations.create', $this->formContext($request->user(), null, null, null, null));
    }

    /**
     * Create from the customer ficha (RF-COT-001): customer_id and an
     * optional default contact pre-fill the form.
     */
    public function createFromCustomer(Request $request, Customer $customer): View
    {
        Gate::authorize('create', Quotation::class);

        return view('quotations.create', $this->formContext(
            $request->user(),
            ['customer_id' => $customer->id, 'customer_name' => $customer->legal_name],
            null,
            null,
            null,
        ));
    }

    /**
     * Create from the lead ficha (RF-COT-001): lead_id pre-fills the form.
     */
    public function createFromLead(Request $request, Lead $lead): View
    {
        Gate::authorize('create', Quotation::class);

        return view('quotations.create', $this->formContext(
            $request->user(),
            null,
            ['lead_id' => $lead->id, 'lead_name' => trim(($lead->first_name.' '.($lead->last_name ?? '')).($lead->company_name ? ' — '.$lead->company_name : ''))],
            null,
            null,
        ));
    }

    /**
     * Create from the opportunity ficha (RF-COT-001): opportunity_id plus
     * the linked lead/customer pre-fill the form.
     */
    public function createFromOpportunity(Request $request, Opportunity $opportunity): View
    {
        Gate::authorize('create', Quotation::class);

        return view('quotations.create', $this->formContext(
            $request->user(),
            $opportunity->customer_id !== null ? ['customer_id' => $opportunity->customer_id] : null,
            $opportunity->lead_id !== null ? ['lead_id' => $opportunity->lead_id] : null,
            ['opportunity_id' => $opportunity->id, 'opportunity_code' => $opportunity->code],
            null,
        ));
    }

    public function store(QuotationStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Quotation::class);

        $quotation = $this->quotations->create($request->validated(), $request->user());

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('status', "Cotización {$quotation->number} creada correctamente.");
    }

    public function edit(Request $request, Quotation $quotation): View
    {
        Gate::authorize('update', $quotation);

        return view('quotations.edit', $this->formContext($request->user(), null, null, null, $quotation));
    }

    public function update(QuotationUpdateRequest $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        try {
            $this->quotations->update($quotation, $request->validated(), $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('status', "Cotización {$quotation->number} actualizada correctamente.");
    }

    /**
     * Detail card (RF-COT-001/004/006): header + line items + activities
     * timeline (via ActivityService if any, plus activitylog for this
     * quotation) + state-machine action buttons.
     *
     * The state machine (RF-COT-004): a draft/sent quotation can be sent,
     * accepted, rejected or duplicated; an accepted/rejected/voided/expired
     * quotation is read-only apart from being voidable when still draft.
     */
    public function show(Quotation $quotation): View
    {
        Gate::authorize('view', $quotation);

        $quotation->load(['owner', 'lead', 'customer', 'contact', 'opportunity', 'currency', 'items.product', 'items.tax']);

        return view('quotations.show', [
            'quotation' => $quotation,
            'history' => $this->historyFor($quotation),
        ]);
    }

    /**
     * PDF generation (RF-COT-005). Spanish d/m/Y dates, mono-column,
     * company header placeholder, customer/lead block, items table with
     * historical tax snapshot, totals, footer.
     */
    public function pdf(Quotation $quotation)
    {
        Gate::authorize('view', $quotation);

        $quotation->load(['owner', 'lead', 'customer', 'contact', 'opportunity', 'currency', 'items.product', 'items.tax']);

        $pdf = Pdf::loadView('quotations.pdf', [
            'quotation' => $quotation,
        ])->setPaper('a4', 'portrait');

        $filename = "{$quotation->number}.pdf";

        return $pdf->download($filename);
    }

    /**
     * POST duplicate (RF-COT-006): clones the quotation into a new draft
     * and redirects to the clone's show page.
     */
    public function duplicate(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($request->user()->can('quotations.create'), 403);
        Gate::authorize('view', $quotation);

        $clone = $this->quotations->duplicate($quotation, $request->user());

        return redirect()
            ->route('quotations.show', $clone)
            ->with('status', "Cotización duplicada como {$clone->number}.");
    }

    /**
     * POST send (RF-COT-004): mark a draft quotation as externally sent.
     */
    public function send(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        if (app(DemoDataGuard::class)->isDemo($quotation)) {
            return back()->with('error', 'La cotización demo no puede marcarse como enviada real.');
        }

        try {
            $this->quotations->markAsSentManually($quotation, $request->user());
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Cotización {$quotation->number} marcada como enviada.");
    }

    public function gmailConfirm(Request $request, Quotation $quotation): View|RedirectResponse
    {
        Gate::authorize('update', $quotation);

        $account = $this->gmailAccountFor($request->user());
        if ($account === null) {
            return back()->with('error', 'Conectá Gmail para enviar esta cotización desde el sistema.');
        }

        $quotation->load(['lead', 'customer.contacts', 'contact', 'owner', 'items.tax']);
        $recipient = $this->suggestRecipient($quotation);
        $template = $this->gmailTemplate($quotation, $request->user(), $recipient['name'] ?? null);

        return view('quotations.gmail-confirm', [
            'quotation' => $quotation,
            'from' => (string) (((array) $account->config_json)['google_account_email'] ?? $request->user()->email),
            'recipient' => $recipient,
            'subject' => $template['subject'],
            'body' => $template['body'],
            'filename' => $this->pdfFilename($quotation),
        ]);
    }

    public function gmailSend(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        if (app(DemoDataGuard::class)->isDemo($quotation)) {
            return back()->with('error', 'La cotización demo no puede enviarse por Gmail.')->withInput();
        }

        $account = $this->gmailAccountFor($request->user());
        if ($account === null) {
            return back()->with('error', 'Conectá Gmail para enviar esta cotización desde el sistema.')->withInput();
        }

        $validated = $request->validate([
            'to' => ['required', 'email', 'max:191'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'bcc' => ['nullable', 'string', 'max:1000'],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $cc = $this->parseEmails((string) ($validated['cc'] ?? ''));
        $bcc = $this->parseEmails((string) ($validated['bcc'] ?? ''));
        if ($cc === null || $bcc === null) {
            return back()->with('error', 'CC/CCO contiene correos inválidos.')->withInput();
        }

        try {
            $pdf = $this->renderQuotationPdf($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $idempotencyKey = hash('sha256', implode('|', [
            $account->getKey(),
            $quotation->getKey(),
            hash('sha256', $pdf['contents']),
            strtolower((string) $validated['to']),
            implode(',', $cc),
            implode(',', $bcc),
            $validated['subject'],
            hash('sha256', $validated['body']),
        ]));

        $existing = EmailMessage::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return back()->with('status', 'Ya existe un envío con estos mismos datos. Revise el estado antes de reenviar.');
        }

        $message = DB::transaction(function () use ($account, $quotation, $request, $validated, $cc, $bcc, $pdf, $idempotencyKey): EmailMessage {
            $message = EmailMessage::query()->create([
                'account_id' => $account->id,
                'direction' => EmailMessage::DIRECTION_OUTBOUND,
                'provider_message_id' => 'pending-'.$idempotencyKey,
                'idempotency_key' => $idempotencyKey,
                'from_email' => (string) (((array) $account->config_json)['google_account_email'] ?? $request->user()->email),
                'from_name' => $request->user()->name,
                'subject' => (string) $validated['subject'],
                'body_html' => [nl2br(e((string) $validated['body']))],
                'body_text' => [(string) $validated['body']],
                'status' => EmailMessage::STATUS_PENDING,
                'related_lead_id' => $quotation->lead_id,
                'related_customer_id' => $quotation->customer_id,
                'related_opportunity_id' => $quotation->opportunity_id,
                'related_quotation_id' => $quotation->id,
                'related_contact_id' => $quotation->contact_id,
                'created_by' => $request->user()->id,
            ]);

            $participants = [[EmailParticipant::KIND_TO, (string) $validated['to']]];
            foreach ($cc as $email) {
                $participants[] = [EmailParticipant::KIND_CC, $email];
            }
            foreach ($bcc as $email) {
                $participants[] = [EmailParticipant::KIND_BCC, $email];
            }
            $participants[] = [EmailParticipant::KIND_FROM, $message->from_email];

            foreach ($participants as [$kind, $email]) {
                EmailParticipant::query()->create(['message_id' => $message->id, 'kind' => $kind, 'email' => $email]);
            }

            $path = 'email-attachments/quotations/'.$message->id.'/'.$pdf['filename'];
            Storage::disk('local')->put($path, $pdf['contents']);
            EmailAttachment::query()->create([
                'message_id' => $message->id,
                'filename' => $pdf['filename'],
                'mime' => 'application/pdf',
                'size' => strlen($pdf['contents']),
                'storage_path' => $path,
                'sha256' => hash('sha256', $pdf['contents']),
            ]);

            return $message;
        });

        if (! app(DemoDataGuard::class)->isEmailMessageDemo($message)) {
            SendEmailMessage::dispatch($message->id);
        }

        return redirect()->route('quotations.show', $quotation)->with('status', 'Envío por Gmail encolado.');
    }

    /**
     * GET accept-confirm (RF-COT-007, ADR-007): when the quotation is
     * linked to an OPEN opportunity, render a confirmation modal asking
     * whether to mark the opportunity as won. When no opportunity, or
     * the opportunity is already won/lost, simply redirect to show with
     * a flash message explaining the next step.
     */
    public function acceptConfirm(Quotation $quotation): RedirectResponse|View
    {
        Gate::authorize('view', $quotation);

        $opp = $quotation->opportunity;
        $oppIsOpen = $opp !== null && ($opp->stage?->stage_type === 'open');

        if (! $oppIsOpen) {
            return redirect()
                ->route('quotations.show', $quotation)
                ->with('status', 'Confirme la aceptación con el botón Aceptar.');
        }

        return view('quotations.accept-confirm', [
            'quotation' => $quotation->load(['owner', 'lead', 'customer', 'opportunity', 'items.tax']),
        ]);
    }

    /**
     * POST accept (RF-COT-007/008, ADR-007): two-step flow.
     *
     * 1. Always accept the quotation (QuotationService::accept).
     * 2. If confirm_opportunity_won=1 AND opportunity is open, mark the
     *    opportunity as won with final_amount = quotation.total and
     *    closed_at = now(). The opportunity event "opportunity-won-from-
     *    quotation" is recorded on the opp's activitylog (the markWon
     *    service already emits "opportunity-won" with the quotation code
     *    in its `withProperties` payload).
     * 3. Otherwise: keep the opportunity untouched (RF-COT-008 allows
     *    accepting a quotation without closing the opportunity).
     */
    public function accept(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($request->user()->can('quotations.accept'), 403);
        Gate::authorize('view', $quotation);

        $confirmOppWon = $request->boolean('confirm_opportunity_won');

        try {
            DB::transaction(function () use ($quotation, $request, $confirmOppWon): void {
                $this->quotations->accept($quotation, $request->user());

                $opp = $quotation->opportunity;
                $oppIsOpen = $opp !== null && ($opp->stage?->stage_type === 'open');

                if ($confirmOppWon && $oppIsOpen) {
                    $this->opportunities->markWon($opp, [
                        'final_amount' => (float) $quotation->total,
                        'closed_at' => now(),
                    ], $request->user());

                    activity()
                        ->performedOn($opp)
                        ->causedBy($request->user())
                        ->event('opportunity-won-from-quotation')
                        ->withProperties([
                            'quotation_number' => $quotation->number,
                            'final_amount' => (float) $quotation->total,
                            'currency_code' => $quotation->currency_code,
                        ])
                        ->log("Oportunidad {$opp->code} ganada al aceptar cotización {$quotation->number}");
                }
            });
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('status', "Cotización {$quotation->number} aceptada.");
    }

    /**
     * POST reject (RF-COT-004): reason is mandatory.
     */
    public function reject(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        try {
            $this->quotations->reject($quotation, $request->user(), $validated['reason']);
        } catch (InvalidOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Cotización {$quotation->number} rechazada.");
    }

    /**
     * POST destroy = anulación (RF-COT-004). The service exposes a "void"
     * path via reject with the dedicated reason. For B06 we simply reuse
     * the service-level operations (soft delete by way of status flip).
     */
    public function destroy(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('delete', $quotation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        DB::transaction(function () use ($quotation, $request, $validated): void {
            $quotation->status = 'voided';
            $quotation->updated_by = $request->user()->id;
            $quotation->save();

            activity()
                ->performedOn($quotation)
                ->causedBy($request->user())
                ->event('quotation-voided')
                ->withProperties([
                    'number' => $quotation->number,
                    'reason' => $validated['reason'],
                ])
                ->log("Cotización {$quotation->number} anulada: {$validated['reason']}");
        });

        return redirect()
            ->route('quotations.index')
            ->with('status', "Cotización {$quotation->number} anulada.");
    }

    /**
     * Export honoring the current list filters (RF-COT-011), scoped by
     * the actor's data visibility (ADR-006).
     */
    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('quotations.export'), 403);

        $filters = $request->only([
            'search', 'status', 'owner_id', 'customer_id', 'lead_id',
            'opportunity_id', 'currency_code', 'issued_at_from', 'issued_at_to',
        ]);

        return Excel::download(
            new QuotationsExport($filters, $request->user()),
            'cotizaciones-'.now()->format('Ymd').'.xlsx',
        );
    }

    /**
     * Shared context for create/edit forms: catalog data + default subject
     * values from the URL context (customer/lead/opportunity) on create,
     * or the record itself on edit. Items are seeded as one empty line so
     * the form has at least one row to start with (RF-COT-001).
     *
     * @param  array{customer_id?: int, customer_name?: string}|null  $customerPrefill
     * @param  array{lead_id?: int, lead_name?: string}|null  $leadPrefill
     * @param  array{opportunity_id?: int, opportunity_code?: string}|null  $oppPrefill
     * @return array<string, mixed>
     */
    private function formContext(
        User $user,
        ?array $customerPrefill,
        ?array $leadPrefill,
        ?array $oppPrefill,
        ?Quotation $quotation,
    ): array {
        $isEdit = $quotation !== null;

        $subject = $isEdit ? $quotation : null;

        $prefill = [
            'customer_id' => old('customer_id', $isEdit ? $quotation->customer_id : ($customerPrefill['customer_id'] ?? null)),
            'lead_id' => old('lead_id', $isEdit ? $quotation->lead_id : ($leadPrefill['lead_id'] ?? null)),
            'opportunity_id' => old('opportunity_id', $isEdit ? $quotation->opportunity_id : ($oppPrefill['opportunity_id'] ?? null)),
            'owner_id' => old('owner_id', $isEdit ? $quotation->owner_id : $user->id),
            'currency_code' => old('currency_code', $isEdit ? $quotation->currency_code : 'PEN'),
            'issued_at' => old('issued_at', $isEdit && $quotation->issued_at ? $quotation->issued_at->format('Y-m-d') : now()->toDateString()),
            'expires_at' => old('expires_at', $isEdit && $quotation->expires_at ? $quotation->expires_at->format('Y-m-d') : null),
            'terms' => old('terms', $isEdit ? $quotation->terms : null),
            'observations' => old('observations', $isEdit ? $quotation->observations : null),
            'contact_id' => old('contact_id', $isEdit ? $quotation->contact_id : null),
        ];

        $items = old('items');

        if ($items === null) {
            if ($isEdit) {
                $items = $quotation->items()->orderBy('id')->get()->map(fn ($i): array => [
                    'product_id' => $i->product_id,
                    'description' => $i->description,
                    'quantity' => (string) $i->quantity,
                    'unit' => $i->unit,
                    'unit_price' => (string) $i->unit_price,
                    'discount_amount' => (string) $i->discount_amount,
                    'tax_id' => $i->tax_id,
                ])->all();
            } else {
                $items = [
                    [
                        'product_id' => null,
                        'description' => '',
                        'quantity' => '1',
                        'unit' => 'unidad',
                        'unit_price' => '0.00',
                        'discount_amount' => '0.00',
                        'tax_id' => null,
                    ],
                ];
            }
        }

        return [
            'quotation' => $subject,
            'prefill' => $prefill,
            'items' => $items,
            'leads' => $this->scope->appliesTo(Lead::query(), $user)->orderByDesc('id')->limit(300)->get(),
            'customers' => $this->scope->appliesTo(Customer::query(), $user)->orderBy('legal_name')->limit(300)->get(),
            'contacts' => Contact::query()
                ->where('is_active', true)
                ->whereHas('customer', fn ($q) => $this->scope->appliesTo($q, $user))
                ->with('customer:id,legal_name')
                ->orderBy('first_name')
                ->limit(500)
                ->get(),
            'opportunities' => $this->scope->appliesTo(Opportunity::query(), $user)->orderByDesc('id')->limit(300)->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->limit(500)->get(),
            'taxes' => Tax::query()->where('is_active', true)->orderBy('sort')->get(),
            'owners' => $this->ownerOptions($user),
        ];
    }

    private function gmailAccountFor(User $user): ?IntegrationAccount
    {
        return IntegrationAccount::query()
            ->active()
            ->where('provider', 'google')
            ->where('owner_id', $user->id)
            ->where('config_json->services->gmail', true)
            ->whereJsonContains('scopes', 'https://www.googleapis.com/auth/gmail.send')
            ->first();
    }

    /** @return array{email: string|null, name: string|null, ambiguous: bool} */
    private function suggestRecipient(Quotation $quotation): array
    {
        if ($quotation->contact && filter_var($quotation->contact->email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $quotation->contact->email, 'name' => trim($quotation->contact->first_name.' '.$quotation->contact->last_name), 'ambiguous' => false];
        }

        $candidates = [];
        if ($quotation->customer) {
            foreach ($quotation->customer->contacts ?? [] as $contact) {
                if ($contact->is_active && filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
                    $candidates[] = ['email' => $contact->email, 'name' => trim($contact->first_name.' '.$contact->last_name)];
                }
            }
            if (filter_var($quotation->customer->email, FILTER_VALIDATE_EMAIL)) {
                $candidates[] = ['email' => $quotation->customer->email, 'name' => $quotation->customer->legal_name];
            }
        }
        if ($quotation->lead && filter_var($quotation->lead->email, FILTER_VALIDATE_EMAIL)) {
            $candidates[] = ['email' => $quotation->lead->email, 'name' => trim($quotation->lead->first_name.' '.$quotation->lead->last_name)];
        }

        $unique = collect($candidates)->unique('email')->values();
        if ($unique->count() === 1) {
            return ['email' => $unique[0]['email'], 'name' => $unique[0]['name'], 'ambiguous' => false];
        }

        return ['email' => null, 'name' => null, 'ambiguous' => $unique->count() > 1];
    }

    /** @return array{subject: string, body: string} */
    private function gmailTemplate(Quotation $quotation, User $user, ?string $contactName): array
    {
        $company = $quotation->customer?->legal_name ?: $quotation->lead?->company_name;
        $subject = 'Cotización '.$quotation->number.($company ? ' – '.$company : '');
        $greeting = $contactName ? 'Hola '.$contactName.':' : 'Hola:';

        return [
            'subject' => $subject,
            'body' => $greeting."\n\nAdjuntamos la cotización {$quotation->number} para su revisión.\n\nQuedamos atentos a cualquier consulta.\n\nSaludos,\n{$user->name}",
        ];
    }

    /** @return list<string>|null */
    private function parseEmails(string $value): ?array
    {
        if (trim($value) === '') {
            return [];
        }
        $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $value) ?: [])));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return null;
            }
        }

        return $emails;
    }

    private function pdfFilename(Quotation $quotation): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $quotation->number) ?: 'cotizacion';
        $safe = trim($safe, '.-_');

        return 'Cotizacion-'.Str::limit($safe, 120, '').'.pdf';
    }

    /** @return array{filename: string, contents: string} */
    private function renderQuotationPdf(Quotation $quotation): array
    {
        $quotation->load(['owner', 'lead', 'customer', 'contact', 'opportunity', 'currency', 'items.product', 'items.tax']);
        $contents = Pdf::loadView('quotations.pdf', ['quotation' => $quotation])->setPaper('a4', 'portrait')->output();
        $filename = $this->pdfFilename($quotation);

        if (! str_starts_with($contents, '%PDF')) {
            throw new \RuntimeException('No se pudo generar un PDF válido para la cotización.');
        }
        if (strlen($contents) > 25 * 1024 * 1024) {
            throw new \RuntimeException('El PDF supera el tamaño máximo permitido por Gmail.');
        }

        return ['filename' => $filename, 'contents' => $contents];
    }

    /**
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
     * Quotation timeline: activitylog entries + related activities on the
     * linked lead/customer/opportunity + its own created/updated info.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function historyFor(Quotation $quotation): Collection
    {
        $entries = collect();

        $entries = $entries->merge(
            \Spatie\Activitylog\Models\Activity::query()
                ->where('subject_type', Quotation::class)
                ->where('subject_id', $quotation->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (\Spatie\Activitylog\Models\Activity $log): array => [
                    'kind' => 'log',
                    'at' => $log->created_at,
                    'title' => $log->description,
                    'detail' => null,
                    'meta' => [
                        'event' => $log->event,
                        'properties' => $log->properties->toArray(),
                    ],
                ]),
        );

        if ($quotation->lead !== null) {
            $entries = $entries->merge(
                $quotation->lead->activities()
                    ->with('type')
                    ->orderByDesc('scheduled_at')
                    ->limit(10)
                    ->get()
                    ->map(fn ($a): array => [
                        'kind' => 'activity',
                        'at' => $a->scheduled_at,
                        'title' => $a->title,
                        'detail' => $a->result ?? $a->description,
                        'meta' => [
                            'origin' => 'lead',
                            'type' => $a->type?->name,
                            'status' => $a->status,
                        ],
                    ]),
            );
        }

        if ($quotation->customer !== null) {
            $entries = $entries->merge(
                $quotation->customer->activities()
                    ->with('type')
                    ->orderByDesc('scheduled_at')
                    ->limit(10)
                    ->get()
                    ->map(fn ($a): array => [
                        'kind' => 'activity',
                        'at' => $a->scheduled_at,
                        'title' => $a->title,
                        'detail' => $a->result ?? $a->description,
                        'meta' => [
                            'origin' => 'customer',
                            'type' => $a->type?->name,
                            'status' => $a->status,
                        ],
                    ]),
            );
        }

        if ($quotation->opportunity !== null) {
            $entries = $entries->merge(
                $quotation->opportunity->activities()
                    ->with('type')
                    ->orderByDesc('scheduled_at')
                    ->limit(10)
                    ->get()
                    ->map(fn ($a): array => [
                        'kind' => 'activity',
                        'at' => $a->scheduled_at,
                        'title' => $a->title,
                        'detail' => $a->result ?? $a->description,
                        'meta' => [
                            'origin' => 'opportunity',
                            'type' => $a->type?->name,
                            'status' => $a->status,
                        ],
                    ]),
            );
        }

        return $entries
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->values();
    }
}