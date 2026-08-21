<?php

namespace App\Exports;

use App\Models\Customer;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Customer export (RF-CLI-003). Spanish headings, dates formatted d/m/Y.
 *
 * Unlike LeadsExport, the query applies the actor's data scope (ADR-006)
 * because customer exports are not a separate permission: users export
 * exactly what they can see in the list.
 *
 * Supported filters: search (code/legal_name/trade_name/doc/email),
 * person_type, owner_id.
 */
class CustomersExport implements FromQuery, WithHeadings, WithMapping
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
     * @return EloquentBuilder<Customer>
     */
    public function query(): EloquentBuilder
    {
        $query = Customer::query()
            ->with(['owner'])
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
                    ->orWhere('legal_name', 'like', $term)
                    ->orWhere('trade_name', 'like', $term)
                    ->orWhere('doc_number', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        foreach (['person_type', 'owner_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
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
            'Tipo de persona',
            'Razón social',
            'Nombre comercial',
            'Tipo de documento',
            'Número de documento',
            'Teléfono',
            'WhatsApp',
            'Correo electrónico',
            'Sitio web',
            'Dirección fiscal',
            'Ubigeo',
            'Sector',
            'Estado',
            'Responsable',
            'Convertido de lead',
            'Fecha de conversión',
            'Observaciones',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * @param  Customer  $customer
     * @return list<mixed>
     */
    public function map($customer): array
    {
        return [
            $customer->code,
            $customer->person_type === 'natural' ? 'Natural' : 'Jurídica',
            $customer->legal_name,
            $customer->trade_name,
            $customer->doc_type,
            $customer->doc_number,
            $customer->phone,
            $customer->whatsapp,
            $customer->email,
            $customer->website,
            $customer->fiscal_address,
            $customer->ubigeo_code,
            $customer->sector,
            $customer->status === 'activo' ? 'Activo' : 'Inactivo',
            $customer->owner?->name,
            $customer->convertedFromLead?->code,
            $customer->converted_at?->format('d/m/Y'),
            $customer->observations,
            $customer->created_at?->format('d/m/Y'),
            $customer->updated_at?->format('d/m/Y'),
        ];
    }
}
