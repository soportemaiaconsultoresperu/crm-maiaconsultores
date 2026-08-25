<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerInvoiceRetireRequest;
use App\Http\Requests\CustomerInvoiceStoreRequest;
use App\Http\Requests\CustomerInvoiceUpdateRequest;
use App\Http\Requests\CustomerPaymentModalityUpdateRequest;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Services\CustomerInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerInvoiceController extends Controller
{
    public function __construct(private readonly CustomerInvoiceService $invoices) {}

    public function updatePaymentModality(CustomerPaymentModalityUpdateRequest $request, Customer $customer): RedirectResponse
    {
        $this->invoices->updatePaymentModality($customer, $request->validated('payment_modality'), $request->user());

        return redirect()->route('customers.show', $customer)->with('status', 'Modalidad de pago actualizada.');
    }

    public function store(CustomerInvoiceStoreRequest $request, Customer $customer): RedirectResponse
    {
        $this->invoices->create($customer, $request->validated(), $request->user());

        return redirect()->route('customers.show', $customer)->with('status', 'Factura creada.');
    }

    public function update(CustomerInvoiceUpdateRequest $request, CustomerInvoice $invoice): RedirectResponse
    {
        $this->invoices->update($invoice, $request->validated(), $request->user());

        return redirect()->route('customers.show', $invoice->customer)->with('status', 'Factura actualizada.');
    }

    public function markPaid(Request $request, CustomerInvoice $invoice): RedirectResponse
    {
        if ($invoice->retired_at !== null) {
            abort(403);
        }

        Gate::authorize('markPaid', $invoice);

        $request->validate($this->forbiddenPaymentMetadataRules());

        try {
            $this->invoices->markPaid($invoice, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('customers.show', $invoice->customer)->withErrors($exception->errors());
        }

        return redirect()->route('customers.show', $invoice->customer)->with('status', 'Factura marcada como pagada.');
    }

    public function retire(CustomerInvoiceRetireRequest $request, CustomerInvoice $invoice): RedirectResponse
    {
        $this->invoices->retire($invoice, $request->user(), $request->validated('reason'));

        return redirect()->route('customers.show', $invoice->customer)->with('status', 'Factura retirada.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function forbiddenPaymentMetadataRules(): array
    {
        return collect([
            'payment_date', 'payment_reference', 'payment_proof', 'partial_amount',
            'partials', 'tax_amount', 'taxes', 'line_item', 'line_items', 'currency',
        ])->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])->all();
    }
}
