<?php

namespace App\Exports;

use App\Models\Lead;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Lead export (RF-LEAD-008). Spanish headings, dates formatted d/m/Y.
 *
 * Accepts the same filter array the list view applies, so exports always
 * match what the user sees. The requesting user's data scope
 * (ADR-006) is applied inside the query: a vendedor only exports their
 * own leads, a supervisor their team's.
 */
class LeadsExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly ?User $actor = null,
    ) {}

    /**
     * Deterministic order (code is unique) so chunked pagination is safe.
     *
     * @return EloquentBuilder<Lead>
     */
    public function query(): EloquentBuilder
    {
        $query = Lead::query()
            ->with(['owner', 'status', 'source'])
            ->orderBy('code')
            ->orderBy('id');

        if ($this->actor !== null) {
            app(DataScopeService::class)->appliesTo($query, $this->actor);
        }

        $filters = $this->filters;

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '\%', trim((string) $filters['search'])).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('doc_number', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        foreach (['status_id', 'source_id', 'owner_id', 'interest_level'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['from'])) {
            $query->whereDate('entered_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('entered_at', '<=', $filters['to']);
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
            'Tipo de persona',
            'Nombres',
            'Apellidos',
            'Empresa',
            'Cargo',
            'Tipo de documento',
            'Número de documento',
            'Teléfono',
            'WhatsApp',
            'Correo electrónico',
            'Dirección',
            'Ubigeo',
            'Origen',
            'Estado',
            'Nivel de interés',
            'Responsable',
            'Fecha de ingreso',
            'Observaciones',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * @param  Lead  $lead
     * @return list<mixed>
     */
    public function map($lead): array
    {
        return [
            $lead->code,
            $lead->person_type === 'natural' ? 'Natural' : 'Jurídica',
            $lead->first_name,
            $lead->last_name,
            $lead->company_name,
            $lead->position,
            $lead->doc_type,
            $lead->doc_number,
            $lead->phone,
            $lead->whatsapp,
            $lead->email,
            $lead->address,
            $lead->ubigeo_code,
            $lead->source?->name,
            $lead->status?->name,
            $lead->interest_level,
            $lead->owner?->name,
            $lead->entered_at?->format('d/m/Y'),
            $lead->observations,
            $lead->created_at?->format('d/m/Y'),
            $lead->updated_at?->format('d/m/Y'),
        ];
    }
}
