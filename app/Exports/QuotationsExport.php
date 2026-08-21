<?php

namespace App\Exports;

use App\Models\Quotation;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Quotation export (RF-COT-011). Spanish headings, dates d/m/Y.
 *
 * The query applies the requesting user's data scope (ADR-006): a
 * vendedor only exports quotations they own; a supervisor their team's.
 * Status and currency are exposed as Spanish labels so reports are
 * directly readable in Excel.
 *
 * Supported filters: search, status, owner_id, customer_id, lead_id,
 * opportunity_id, currency_code, issued_at_from, issued_at_to.
 */
class QuotationsExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly ?User $actor = null,
    ) {}

    /**
     * Deterministic order (number is unique) so chunked pagination is safe.
     *
     * @return EloquentBuilder<Quotation>
     */
    public function query(): EloquentBuilder
    {
        $query = Quotation::query()
            ->with(['owner', 'lead', 'customer', 'opportunity', 'currency'])
            ->orderBy('number')
            ->orderBy('id');

        if ($this->actor !== null) {
            app(DataScopeService::class)->appliesTo($query, $this->actor);
        }

        $filters = $this->filters;

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '\%', trim((string) $filters['search'])).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('number', 'like', $term)
                    ->orWhere('terms', 'like', $term)
                    ->orWhere('observations', 'like', $term);
            });
        }

        foreach (['status', 'owner_id', 'customer_id', 'lead_id', 'opportunity_id', 'currency_code'] as $column) {
            if (! empty($filters[$column])) {
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
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Número',
            'Estado',
            'Lead',
            'Cliente',
            'Oportunidad',
            'Responsable',
            'Fecha de emisión',
            'Fecha de expiración',
            'Moneda',
            'Subtotal',
            'Descuento',
            'Impuesto',
            'Total',
            'Fecha de aceptación',
            'Términos',
            'Observaciones',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * @param  Quotation  $quotation
     * @return list<mixed>
     */
    public function map($quotation): array
    {
        return [
            $quotation->number,
            $this->statusLabel($quotation->status),
            $quotation->lead?->code,
            $quotation->customer?->code,
            $quotation->opportunity?->code,
            $quotation->owner?->name,
            $quotation->issued_at?->format('d/m/Y'),
            $quotation->expires_at?->format('d/m/Y'),
            $quotation->currency_code,
            (float) $quotation->subtotal,
            (float) $quotation->discount_total,
            (float) $quotation->tax_total,
            (float) $quotation->total,
            $quotation->accepted_at?->format('d/m/Y H:i'),
            $quotation->terms,
            $quotation->observations,
            $quotation->created_at?->format('d/m/Y'),
            $quotation->updated_at?->format('d/m/Y'),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador',
            'sent' => 'Enviada',
            'accepted' => 'Aceptada',
            'rejected' => 'Rechazada',
            'expired' => 'Vencida',
            'voided' => 'Anulada',
            default => $status,
        };
    }
}
