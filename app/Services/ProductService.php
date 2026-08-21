<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product catalog business logic (RF-PROD-001..003).
 *
 * - PROD-YYYY-NNNNN code generated inside the same transaction as the
 *   insert (ADR-002).
 * - Products are a global catalog (no team/own scope on the data itself):
 *   only module permissions gate access.
 * - Soft-delete deactivation with a reason (RF-PROD-002, RNF-DAT-001).
 * - exportQuery() returns a DataScopeService-scoped builder; since
 *   products have no owner_id, the scope resolves to "no restriction"
 *   for admin / supervisor / vendedor (all see the global catalog) and
 *   applies only when downstream callers add their own filters.
 */
class ProductService
{
    public function __construct(
        private readonly CodeGeneratorService $codes,
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Create a product: code + audit columns in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Product
    {
        return DB::transaction(function () use ($data, $actor): Product {
            $data['code'] = $this->codes->next('product');
            $data['is_active'] ??= true;

            $product = new Product($data);
            $product->created_by = $actor->id;
            $product->updated_by = $actor->id;
            $product->save();

            return $product->refresh();
        });
    }

    /**
     * Update a product. The code is never editable.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, User $actor): Product
    {
        DB::transaction(function () use ($product, $data, $actor): void {
            unset($data['code'], $data['created_by'], $data['updated_by']);

            $product->fill($data);
            $product->updated_by = $actor->id;
            $product->save();
        });

        return $product->refresh();
    }

    /**
     * Soft-delete deactivation with a mandatory reason (RF-PROD-002).
     */
    public function deactivate(Product $product, User $actor, string $reason): Product
    {
        DB::transaction(function () use ($product, $actor, $reason): void {
            $product->updated_by = $actor->id;
            $product->delete();

            activity()
                ->performedOn($product)
                ->causedBy($actor)
                ->event('product-deactivated')
                ->withProperties(['reason' => $reason])
                ->log("Producto {$product->code} desactivado: {$reason}");
        });

        return $product;
    }

    /**
     * Owner-scoped query for list pages. Products have no owner_id, so
     * the owner scope is a no-op (every user can see every product via
     * the products.view.* permissions). Filters are applied on top.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function exportQuery(User $user, array $filters): Builder
    {
        $query = Product::query()
            ->with(['category', 'tax', 'currency'])
            ->orderBy('code')
            ->orderBy('id');

        $this->dataScope->appliesTo($query, $user, 'owner_id');

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '\%', trim((string) $filters['search'])).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        foreach (['product_type', 'category_id', 'currency_code', 'tax_id', 'is_active'] as $column) {
            if ($filters[$column] ?? null) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }
}
