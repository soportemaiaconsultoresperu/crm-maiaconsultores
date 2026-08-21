<?php

namespace App\Services;

use App\Events\V2\QuotationAccepted;
use App\Events\V2\QuotationCreated;
use App\Events\V2\QuotationSent;
use App\Exceptions\InvalidOperationException;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quotation business logic (RF-COT-001..011).
 *
 * - Codes COT-YYYY-NNNNN generated in the same transaction as the
 *   insert (ADR-002).
 * - Exactly one of lead_id/customer_id (RF-COT-001, docs §3.7).
 * - Totals are recalculated server-side from items (ADR-005,
 *   RF-COT-003); the payload is NEVER trusted.
 * - tax_id / tax_name / tax_rate are copied historically on each line
 *   (ADR-005, RF-COT-009); later catalog changes never alter the
 *   quotation.
 * - unit_price is the price being quoted today and may differ from
 *   product.price (RF-COT-003, RF-PROD-003).
 * - Status transitions: draft → sent → accepted | rejected. Acceptance
 *   does NOT auto-mark the opportunity as won (ADR-007): the controller
 *   collects an explicit confirmation and calls
 *   OpportunityService::markWon separately.
 * - Duplicates are new draft quotations with re-snapshotted tax and
 *   items (RF-COT-006).
 */
class QuotationService
{
    public function __construct(
        private readonly CodeGeneratorService $codes,
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Create a quotation in one transaction: code, header, items, totals,
     * activitylog entry. Server-side recalculation ignores payload totals.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException When neither or both of
     *                                   lead_id/customer_id is set, or
     *                                   when items is empty.
     */
    public function create(array $data, User $actor): Quotation
    {
        $this->assertCreatable($data);

        return DB::transaction(function () use ($data, $actor): Quotation {
            $data['number'] = $this->codes->next('quotation');
            $data['currency_code'] ??= $this->defaultCurrency();
            $data['owner_id'] ??= $actor->id;
            $data['status'] ??= 'draft';
            $data['issued_at'] ??= now()->toDateString();
            $data['subtotal'] = 0;
            $data['discount_total'] = 0;
            $data['tax_total'] = 0;
            $data['total'] = 0;

            $items = $data['items'] ?? [];
            unset($data['items']);

            $quotation = new Quotation($data);
            $quotation->created_by = $actor->id;
            $quotation->updated_by = $actor->id;
            $quotation->save();

            $this->replaceItems($quotation, $items, $actor);
            $this->calculateTotals($quotation);

            activity()
                ->performedOn($quotation)
                ->causedBy($actor)
                ->event('quotation-created')
                ->withProperties([
                    'code' => $quotation->number,
                    'total' => (float) $quotation->total,
                    'currency_code' => $quotation->currency_code,
                    'item_count' => $quotation->items()->count(),
                ])
->log("Cotización {$quotation->number} creada");

            return $quotation->refresh();
        });

        $quotation->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new QuotationCreated($quotation, $actor));

        return $quotation;
    }

    /**
     * Update a draft quotation in one transaction. The code never changes.
     * Existing items are deleted and recreated from the new payload so the
     * historical tax snapshot stays consistent with the payload.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidOperationException When the quotation is not in
     *                                   draft status.
     */
    public function update(Quotation $quotation, array $data, User $actor): Quotation
    {
        $this->assertDraft($quotation);

        if (array_key_exists('items', $data)) {
            $this->assertHasItems($data['items']);
        }

        return DB::transaction(function () use ($quotation, $data, $actor): Quotation {
            unset($data['number'], $data['code'], $data['status'], $data['accepted_at'],
                $data['created_by'], $data['updated_by']);

            $items = $data['items'] ?? null;
            unset($data['items']);

            $quotation->fill($data);
            $quotation->updated_by = $actor->id;
            $quotation->save();

            if ($items !== null) {
                $this->replaceItems($quotation, $items, $actor);
            }

            $this->calculateTotals($quotation);

            return $quotation->refresh();
        });
    }

    /**
     * Duplicate a quotation into a new draft. New code, same header links,
     * items re-snapshotted from current Tax catalog (RF-COT-006).
     */
    public function duplicate(Quotation $source, User $actor): Quotation
    {
        return DB::transaction(function () use ($source, $actor): Quotation {
            $clone = $source->replicate([
                'number', 'subtotal', 'discount_total', 'tax_total', 'total',
                'accepted_at', 'created_by', 'updated_by',
            ]);
            $clone->number = $this->codes->next('quotation');
            $clone->status = 'draft';
            $clone->accepted_at = null;
            $clone->owner_id = $actor->id;
            $clone->created_by = $actor->id;
            $clone->updated_by = $actor->id;
            $clone->save();

            $items = $source->items()->get()->map(fn (QuotationItem $item): array => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (string) $item->unit_price,
                'discount_amount' => (string) $item->discount_amount,
                'tax_id' => $item->tax_id,
            ])->all();

            $this->replaceItems($clone, $items, $actor);
            $this->calculateTotals($clone);

            activity()
                ->performedOn($clone)
                ->causedBy($actor)
                ->event('quotation-duplicated')
                ->withProperties([
                    'source_number' => $source->number,
                    'new_number' => $clone->number,
                ])
                ->log("Cotización {$source->number} duplicada como {$clone->number}");

            return $clone->refresh();
        });
    }

    /**
     * Move a draft quotation to "sent". issued_at is preserved when
     * already set, otherwise set to today.
     */
    public function send(Quotation $quotation, User $actor): Quotation
    {
        if ($quotation->status !== 'draft') {
            throw new InvalidOperationException(
                "La cotización {$quotation->number} no está en borrador y no puede enviarse."
            );
        }

        return DB::transaction(function () use ($quotation, $actor): Quotation {
            $quotation->status = 'sent';
            $quotation->issued_at ??= now()->toDateString();
            $quotation->updated_by = $actor->id;
            $quotation->save();

            activity()
                ->performedOn($quotation)
                ->causedBy($actor)
                ->event('quotation-sent')
                ->withProperties(['number' => $quotation->number])
->log("Cotización {$quotation->number} enviada");

            return $quotation->refresh();
        });

        $quotation->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new QuotationSent($quotation, $actor));

        return $quotation;
    }

    /**
     * Accept a sent or draft quotation. accepted_at is set. The opportunity
     * is NOT mutated here — the controller collects the explicit
     * confirmation (ADR-007) and calls OpportunityService::markWon
     * separately. Accepting without an opportunity is fine (RF-COT-008).
     */
    public function accept(Quotation $quotation, User $actor, ?string $note = null): Quotation
    {
        if (! in_array($quotation->status, ['draft', 'sent'], true)) {
            throw new InvalidOperationException(
                "La cotización {$quotation->number} está en estado {$quotation->status} y no puede aceptarse."
            );
        }

        return DB::transaction(function () use ($quotation, $actor, $note): Quotation {
            $quotation->status = 'accepted';
            $quotation->accepted_at = now();
            $quotation->updated_by = $actor->id;
            $quotation->save();

            activity()
                ->performedOn($quotation)
                ->causedBy($actor)
                ->event('quotation-accepted')
                ->withProperties([
                    'number' => $quotation->number,
                    'total' => (float) $quotation->total,
                    'opportunity_id' => $quotation->opportunity_id,
                    'note' => $note,
                ])
->log("Cotización {$quotation->number} aceptada");

            return $quotation->refresh();
        });

        $quotation->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new QuotationAccepted($quotation, $actor));

        return $quotation;
    }

    /**
     * Reject a sent or draft quotation with a mandatory reason.
     */
    public function reject(Quotation $quotation, User $actor, string $reason): Quotation
    {
        if (! in_array($quotation->status, ['draft', 'sent'], true)) {
            throw new InvalidOperationException(
                "La cotización {$quotation->number} está en estado {$quotation->status} y no puede rechazarse."
            );
        }

        return DB::transaction(function () use ($quotation, $actor, $reason): Quotation {
            $quotation->status = 'rejected';
            $quotation->updated_by = $actor->id;
            $quotation->save();

            activity()
                ->performedOn($quotation)
                ->causedBy($actor)
                ->event('quotation-rejected')
                ->withProperties([
                    'number' => $quotation->number,
                    'reason' => $reason,
                ])
                ->log("Cotización {$quotation->number} rechazada: {$reason}");

            return $quotation->refresh();
        });
    }

    /**
     * Sync header totals from the current items and persist them.
     * Always called after replaceItems() so item data is the single
     * source of truth.
     */
    public function calculateTotals(Quotation $quotation): Quotation
    {
        $items = $quotation->items()->get();

        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item->line_subtotal;
            $discount += (float) $item->discount_amount;
            $tax += (float) $item->line_tax;
        }

        $total = round($subtotal - $discount + $tax, 2);

        $quotation->subtotal = round($subtotal, 2);
        $quotation->discount_total = round($discount, 2);
        $quotation->tax_total = round($tax, 2);
        $quotation->total = $total;
        $quotation->save();

        return $quotation->refresh();
    }

    /**
     * Owner-scoped query for list pages (ADR-006).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Quotation>
     */
    public function exportQuery(User $user, array $filters): Builder
    {
        $query = Quotation::query()
            ->with(['owner', 'lead', 'customer', 'opportunity', 'currency'])
            ->orderBy('number')
            ->orderBy('id');

        $this->dataScope->appliesTo($query, $user, 'owner_id');

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '\%', trim((string) $filters['search'])).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('number', 'like', $term)
                    ->orWhere('terms', 'like', $term)
                    ->orWhere('observations', 'like', $term);
            });
        }

        foreach (['status', 'owner_id', 'customer_id', 'lead_id', 'opportunity_id', 'currency_code'] as $column) {
            if ($filters[$column] ?? null) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['issued_at_from'])) {
            $query->whereDate('issued_at', '>=', $filters['issued_at_from']);
        }

        if (! empty($filters['issued_at_to'])) {
            $query->whereDate('issued_at', '<=', $filters['issued_at_to']);
        }

        return $query;
    }

    /**
     * Recreate the quotation's items from a raw payload (delete + insert
     * in one transaction). Each item copies tax_id/tax_name/tax_rate
     * historically (ADR-005); unit_price is whatever the payload says
     * (RF-COT-003).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(Quotation $quotation, array $items, User $actor): void
    {
        $quotation->items()->delete();

        foreach ($items as $payload) {
            $quantity = (float) ($payload['quantity'] ?? 0);
            $unitPrice = (float) ($payload['unit_price'] ?? 0);
            $discount = (float) ($payload['discount_amount'] ?? 0);
            $taxId = $payload['tax_id'] ?? null;

            $taxName = null;
            $taxRate = 0.0;

            if (! empty($taxId)) {
                $tax = Tax::query()->find((int) $taxId);
                if ($tax !== null) {
                    $taxName = $tax->name;
                    $taxRate = (float) $tax->rate;
                } else {
                    $taxId = null;
                }
            }

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineDiscount = round($discount, 2);
            $lineTax = $taxId
                ? round(($lineSubtotal - $lineDiscount) * $taxRate / 100, 2)
                : 0.0;
            $lineTotal = round($lineSubtotal - $lineDiscount + $lineTax, 2);

            $item = new QuotationItem([
                'quotation_id' => $quotation->id,
                'product_id' => $payload['product_id'] ?? null,
                'description' => $payload['description'] ?? '',
                'quantity' => $quantity,
                'unit' => $payload['unit'] ?? null,
                'unit_price' => $unitPrice,
                'discount_amount' => $lineDiscount,
                'tax_id' => $taxId,
                'tax_name' => $taxName ?? '',
                'tax_rate' => $taxId ? $taxRate : 0,
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal,
            ]);
            $item->created_by = $actor->id;
            $item->updated_by = $actor->id;
            $item->save();
        }
    }

    /**
     * Minimum service-level invariants before opening the create
     * transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCreatable(array $data): void
    {
        $hasLead = ! empty($data['lead_id']);
        $hasCustomer = ! empty($data['customer_id']);

        if ($hasLead === $hasCustomer) {
            throw new \InvalidArgumentException(
                'La cotización debe indicar exactamente uno de lead o cliente.'
            );
        }

        $this->assertHasItems($data['items'] ?? []);
    }

    /**
     * @param  mixed  $items
     */
    private function assertHasItems($items): void
    {
        if (! is_array($items) || count($items) < 1) {
            throw new \InvalidArgumentException(
                'La cotización debe tener al menos un ítem.'
            );
        }
    }

    /**
     * Only draft quotations are editable.
     */
    private function assertDraft(Quotation $quotation): void
    {
        if ($quotation->status !== 'draft') {
            throw new InvalidOperationException(
                "La cotización {$quotation->number} está en estado {$quotation->status} y no admite cambios."
            );
        }
    }

    /**
     * Default currency code from settings (ADR-004).
     */
    private function defaultCurrency(): string
    {
        $value = Setting::query()->where('key', 'currency_default')->value('value');

        return $value === null ? 'PEN' : (string) $value;
    }
}
