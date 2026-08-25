<?php

namespace App\Services;

use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use App\Support\Invoices\OverdueInvoiceResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OverdueInvoiceProcessor
{
    public function process(?CarbonInterface $today = null, ?User $actor = null): OverdueInvoiceResult
    {
        $today ??= now();
        $query = CustomerInvoice::query()->eligibleForOverdueProcessing($today);
        $scanned = (clone $query)->count();
        $updated = 0;

        $query->with('status')->orderBy('id')->each(function (CustomerInvoice $invoice) use ($today, $actor, &$updated): void {
            if ($this->processInvoice($invoice, $today, $actor)) {
                $updated++;
            }
        });

        return new OverdueInvoiceResult(scanned: $scanned, updated: $updated, skipped: $scanned - $updated);
    }

    public function processInvoice(CustomerInvoice $invoice, ?CarbonInterface $today = null, ?User $actor = null): bool
    {
        $today ??= now();
        $invoice->loadMissing('status');

        if (! $invoice->isEligibleForOverdueProcessing($today)) {
            return false;
        }

        $overdue = $this->overdueStatus();
        $oldStatus = $invoice->status?->slug;

        return DB::transaction(function () use ($invoice, $overdue, $oldStatus, $today, $actor): bool {
            $affected = CustomerInvoice::query()
                ->whereKey($invoice->id)
                ->eligibleForOverdueProcessing($today)
                ->update(['status_id' => $overdue->id, 'updated_at' => now()]);

            if ($affected !== 1) {
                return false;
            }

            $invoice->forceFill(['status_id' => $overdue->id])->setRelation('status', $overdue);

            activity()
                ->performedOn($invoice)
                ->when($actor !== null, fn ($log) => $log->causedBy($actor))
                ->event('customer-invoice-marked-overdue')
                ->withProperties([
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_id' => $invoice->customer_id,
                    'old_status_slug' => $oldStatus,
                    'new_status_slug' => $overdue->slug,
                    'due_date' => optional($invoice->due_date)->toDateString(),
                    'processed_date' => $today->toDateString(),
                    'actor_type' => $actor ? 'user' : 'system_command',
                    'system_action' => 'invoices:mark-overdue',
                ])
                ->log("Factura {$invoice->invoice_number} marcada como vencida");

            return true;
        });
    }

    private function overdueStatus(): InvoiceStatus
    {
        $status = InvoiceStatus::query()
            ->where('slug', InvoiceStatus::SLUG_OVERDUE)
            ->where('is_active', true)
            ->first();

        if (! $status) {
            throw ValidationException::withMessages([
                'status' => 'El estado base Vencida no está disponible. Revise Catálogos.',
            ]);
        }

        return $status;
    }
}
