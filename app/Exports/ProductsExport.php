<?php

namespace App\Exports;

use App\Models\Product;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Product catalog export (RF-PROD-001..003). Spanish headings, dates d/m/Y.
 *
 * The query applies the requesting user's data scope (ADR-006): products
 * are a global catalog with no owner_id, so the scope resolves to "no
 * restriction" for admin / supervisor / vendedor (every catalog row is
 * visible to every authenticated product user).
 *
 * Supported filters: search, product_type, category_id, currency_code,
 * tax_id, is_active.
 */
class ProductsExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly ?User $actor = null,
    ) {}

    /**
     * Deterministic order (code is unique per year) so chunked pagination
     * is safe.
     *
     * @return EloquentBuilder<Product>
     */
    public function query(): EloquentBuilder
    {
        $query = Product::query()
            ->with(['category', 'tax', 'currency'])
            ->orderBy('code')
            ->orderBy('id');

        if ($this->actor !== null) {
            // Products carry no owner_id, so this is effectively a no-op
            // (DataScopeService::appliesTo detects the missing column).
            app(DataScopeService::class)->appliesTo($query, $this->actor);
        }

        $filters = $this->filters;

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '\%', trim((string) $filters['search'])).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        foreach (['product_type', 'category_id', 'currency_code', 'tax_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
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
            'Tipo',
            'Nombre',
            'Categoría',
            'Descripción',
            'Precio',
            'Moneda',
            'Impuesto',
            'Activo',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * @param  Product  $product
     * @return list<mixed>
     */
    public function map($product): array
    {
        return [
            $product->code,
            $product->product_type === 'servicio' ? 'Servicio' : 'Producto',
            $product->name,
            $product->category?->name,
            $product->description,
            (float) $product->price,
            $product->currency_code,
            $product->tax?->name,
            $product->is_active ? 'Sí' : 'No',
            $product->created_at?->format('d/m/Y'),
            $product->updated_at?->format('d/m/Y'),
        ];
    }
}