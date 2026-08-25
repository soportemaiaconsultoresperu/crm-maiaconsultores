<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerInvoiceService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data, User $actor): CustomerInvoice
    {
        return DB::transaction(function () use ($customer, $data, $actor): CustomerInvoice {
            $invoice = new CustomerInvoice($this->invoiceData($data));
            $invoice->customer()->associate($customer);
            $invoice->created_by = $actor->id;
            $invoice->updated_by = $actor->id;
            $invoice->save();

            $this->log($invoice, $actor, 'customer-invoice-created', 'creada');
            app(OverdueInvoiceProcessor::class)->processInvoice($invoice, actor: $actor);

            return $invoice->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerInvoice $invoice, array $data, User $actor): CustomerInvoice
    {
        $this->assertActive($invoice);

        return DB::transaction(function () use ($invoice, $data, $actor): CustomerInvoice {
            $invoice->fill($this->invoiceData($data));
            $invoice->updated_by = $actor->id;
            $invoice->save();

            $this->log($invoice, $actor, 'customer-invoice-updated', 'actualizada');
            app(OverdueInvoiceProcessor::class)->processInvoice($invoice, actor: $actor);

            return $invoice->refresh();
        });
    }

    public function markPaid(CustomerInvoice $invoice, User $actor): CustomerInvoice
    {
        $this->assertActive($invoice);
        $paid = $this->statusBySlug(InvoiceStatus::SLUG_PAID);

        return DB::transaction(function () use ($invoice, $actor, $paid): CustomerInvoice {
            $oldStatus = $invoice->status?->slug;
            $invoice->status()->associate($paid);
            $invoice->updated_by = $actor->id;
            $invoice->save();

            $this->log($invoice, $actor, 'customer-invoice-marked-paid', 'marcada como pagada', [
                'old_status_slug' => $oldStatus,
                'new_status_slug' => $paid->slug,
            ]);

            return $invoice->refresh();
        });
    }

    public function retire(CustomerInvoice $invoice, User $actor, string $reason): CustomerInvoice
    {
        $this->assertActive($invoice);

        return DB::transaction(function () use ($invoice, $actor, $reason): CustomerInvoice {
            $invoice->retired_at = now();
            $invoice->retired_by = $actor->id;
            $invoice->retire_reason = $reason;
            $invoice->updated_by = $actor->id;
            $invoice->save();

            $this->log($invoice, $actor, 'customer-invoice-retired', 'retirada', ['reason' => $reason]);

            return $invoice->refresh();
        });
    }

    public function updatePaymentModality(Customer $customer, ?string $modality, User $actor): Customer
    {
        $old = $customer->payment_modality;
        $customer->payment_modality = $modality;
        $customer->updated_by = $actor->id;
        $customer->save();

        activity()
            ->performedOn($customer)
            ->causedBy($actor)
            ->event('customer-payment-modality-updated')
            ->withProperties(['old' => $old, 'new' => $modality, 'customer_id' => $customer->id])
            ->log('Modalidad de pago de cliente actualizada');

        return $customer->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function invoiceData(array $data): array
    {
        return array_intersect_key($data, array_flip(['invoice_number', 'due_date', 'total_amount', 'status_id', 'notes']));
    }

    private function statusBySlug(string $slug): InvoiceStatus
    {
        $status = InvoiceStatus::query()->where('slug', $slug)->where('is_active', true)->first();

        if (! $status) {
            throw ValidationException::withMessages([
                'status' => 'El estado base de factura no está disponible. Revise Catálogos.',
            ]);
        }

        return $status;
    }

    private function assertActive(CustomerInvoice $invoice): void
    {
        if ($invoice->retired_at !== null) {
            throw ValidationException::withMessages([
                'invoice' => 'La factura retirada/anulada no admite cambios normales.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(CustomerInvoice $invoice, User $actor, string $event, string $action, array $extra = []): void
    {
        activity()
            ->performedOn($invoice)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(array_merge([
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
                'status_slug' => $invoice->status?->slug,
                'due_date' => optional($invoice->due_date)->toDateString(),
                'total_amount' => (float) $invoice->total_amount,
            ], $extra))
            ->log("Factura {$invoice->invoice_number} {$action}");
    }
}
