<?php

namespace App\Http\Controllers;

use App\Exceptions\ConversionException;
use App\Http\Requests\CustomerStoreRequest;
use App\Models\Lead;
use App\Services\LeadConversionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * B03 Lead → customer conversion UI (RF-LEAD-013, ADR-001). The GET form
 * prefills customer + optional contact fields from the lead; the POST runs
 * LeadConversionService::convert inside one transaction. A double
 * conversion surfaces as a flash error on the lead's ficha, never as a
 * second customer.
 */
class LeadConversionController extends Controller
{
    public function __construct(
        private readonly LeadConversionService $conversion,
    ) {}

    public function create(Request $request, Lead $lead): View|RedirectResponse
    {
        abort_unless($request->user()->can('leads.convert'), 403);
        Gate::authorize('view', $lead);

        // A lead that was already converted (or reached a final status) has
        // no conversion form to show — bounce to its ficha (ADR-001).
        if ($lead->convertedCustomers()->exists() || $lead->status?->is_final) {
            return redirect()
                ->route('leads.show', $lead)
                ->with('error', "El prospecto {$lead->code} ya no puede convertirse a cliente.");
        }

        return view('leads.convert', [
            'lead' => $lead->load(['owner', 'status', 'ubigeo']),
            'prefill' => $this->prefillFrom($lead),
        ]);
    }

    /**
     * Validate via CustomerStoreRequest (customer fields) plus an optional
     * contact subset, then convert. ConversionException (already converted
     * / final status) bounces back to the lead with an error flash and no
     * partial state.
     */
    public function store(CustomerStoreRequest $request, Lead $lead): RedirectResponse
    {
        abort_unless($request->user()->can('leads.convert'), 403);
        Gate::authorize('view', $lead);

        $request->validate([
            'contact' => ['nullable', 'array'],
            'contact.first_name' => ['required_with:contact.last_name,contact.position,contact.phone,contact.whatsapp,contact.email', 'nullable', 'string', 'max:100'],
            'contact.last_name' => ['required_with:contact.first_name,contact.position,contact.phone,contact.whatsapp,contact.email', 'nullable', 'string', 'max:100'],
            'contact.position' => ['nullable', 'string', 'max:100'],
            'contact.phone' => ['nullable', 'string', 'max:30'],
            'contact.whatsapp' => ['nullable', 'string', 'max:30'],
            'contact.email' => ['nullable', 'email', 'max:150'],
        ]);

        $contactData = collect($request->input('contact', []))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        if (! empty($contactData) && (empty($contactData['first_name']) || empty($contactData['last_name']))) {
            return back()->withInput()->with('error', 'Para crear un contacto inicial indique nombres y apellidos.');
        }

        try {
            $customer = $this->conversion->convert(
                $lead,
                $request->validated(),
                $request->user(),
                $contactData === [] ? null : $contactData,
            );
        } catch (ConversionException $exception) {
            return redirect()
                ->route('leads.show', $lead)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Prospecto {$lead->code} convertido: cliente {$customer->code} creado correctamente.");
    }

    /**
     * Lead → customer field mapping for the form prefill: the company (or
     * the person's full name) becomes legal_name; the person becomes the
     * suggested first contact.
     *
     * @return array<string, mixed>
     */
    private function prefillFrom(Lead $lead): array
    {
        return [
            'person_type' => $lead->person_type,
            'legal_name' => trim($lead->company_name
                ?? trim("{$lead->first_name} {$lead->last_name}")),
            'trade_name' => null,
            'doc_type' => $lead->doc_type,
            'doc_number' => $lead->doc_number,
            'phone' => $lead->phone,
            'whatsapp' => $lead->whatsapp,
            'email' => $lead->email,
            'fiscal_address' => $lead->address,
            'ubigeo_code' => $lead->ubigeo_code,
            'owner_id' => $lead->owner_id,
            'contact_first_name' => $lead->first_name,
            'contact_last_name' => $lead->last_name,
            'contact_position' => $lead->position,
            'contact_phone' => $lead->phone,
            'contact_whatsapp' => $lead->whatsapp,
            'contact_email' => $lead->email,
        ];
    }
}
