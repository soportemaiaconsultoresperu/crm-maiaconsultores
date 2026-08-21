<?php

namespace App\Exports;

use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Opportunity export (RF-OPP-008). Spanish headings, dates d/m/Y, amounts
 * as raw numbers plus a currency column (no symbol glued to the number).
 *
 * Accepts the same filter array the list view applies, so exports always
 * match what the user sees. Supported filters: search (code/title/customer
 * or lead name), stage_id, owner_id, priority, status (open|won|lost).
 */
class OpportunitiesExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  EloquentBuilder<Opportunity>|null  $scopedQuery  Pre-scoped
     *                                                          query injected by the controller (owner visibility, RF-OPP-010);
     *                                                          when null the query is built from the filters alone (unit use).
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly ?EloquentBuilder $scopedQuery = null,
    ) {}

    /**
     * Deterministic order (code is unique) so chunked pagination is safe.
     *
     * @return EloquentBuilder<Opportunity>
     */
    public function query(): EloquentBuilder
    {
        if ($this->scopedQuery !== null) {
            return $this->scopedQuery->with(['owner', 'stage', 'lead', 'customer', 'lossReason']);
        }

        $query = Opportunity::query()
            ->with(['owner', 'stage', 'lead', 'customer', 'lossReason'])
            ->orderBy('code')
            ->orderBy('id');

        $filters = $this->filters;

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '\%', trim((string) $filters['search'])).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('legal_name', 'like', $term))
                    ->orWhereHas('lead', function ($l) use ($term): void {
                        $l->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('company_name', 'like', $term);
                    });
            });
        }

        foreach (['stage_id', 'owner_id', 'priority'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];

            if (in_array($status, ['open', 'won', 'lost'], true)) {
                $query->whereHas('stage', fn ($s) => $s->where('stage_type', $status));
            }
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Código',
            'Título',
            'Sujeto',
            'Etapa',
            'Estado',
            'Monto estimado',
            'Moneda',
            'Probabilidad (%)',
            'Monto final',
            'Motivo de pérdida',
            'Prioridad',
            'Responsable',
            'Cierre estimado',
            'Cierre real',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * @param  Opportunity  $opportunity
     * @return list<mixed>
     */
    public function map($opportunity): array
    {
        $subject = $opportunity->customer
            ? $opportunity->customer->legal_name
            : trim(($opportunity->lead?->first_name.' '.($opportunity->lead?->last_name ?? '')).($opportunity->lead?->company_name ? ' — '.$opportunity->lead->company_name : ''));

        $statusMap = ['open' => 'Abierta', 'won' => 'Ganada', 'lost' => 'Perdida'];

        return [
            $opportunity->code,
            $opportunity->title,
            $subject,
            $opportunity->stage?->name,
            $statusMap[$opportunity->stage?->stage_type ?? 'open'] ?? '',
            (float) $opportunity->estimated_amount,
            $opportunity->currency_code,
            $opportunity->probability !== null ? (float) $opportunity->probability : null,
            $opportunity->final_amount !== null ? (float) $opportunity->final_amount : null,
            $opportunity->lossReason?->name,
            $opportunity->priority !== null ? ucfirst($opportunity->priority) : null,
            $opportunity->owner?->name,
            $opportunity->expected_close_at?->format('d/m/Y'),
            $opportunity->closed_at?->format('d/m/Y'),
            $opportunity->created_at?->format('d/m/Y'),
            $opportunity->updated_at?->format('d/m/Y'),
        ];
    }
}
