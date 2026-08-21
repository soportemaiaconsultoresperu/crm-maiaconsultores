<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customer ↔ Product association (catalog products linked to a customer).
 *
 * Authorization mirrors the contacts pattern: the customer context is
 * checked via Gate::authorize('view', $customer), then the specific
 * action is gated on `customers.update` (attaching/detaching a product is
 * a write on the customer record).
 */
class CustomerProductController extends Controller
{
    /**
     * Attach a catalog product to a customer. Idempotent: re-attaching the
     * same product is a no-op (syncWithoutDetaching).
     */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('view', $customer);
        abort_unless($request->user()->can('customers.update'), 403);

        $data = $request->validate($this->rules(), [], $this->attributeNames());

        $pivot = $this->pivotAttributes($data);

        $customer->products()->syncWithoutDetaching([
            $data['product_id'] => $pivot,
        ]);

        $product = Product::query()->findOrFail($data['product_id']);

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Producto {$product->code} asociado al cliente.");
    }

    /**
     * Update an existing pivot (notes, quantity, price_override, dates).
     * The product association is preserved — only the extra fields change.
     */
    public function update(Request $request, Customer $customer, Product $product): RedirectResponse
    {
        Gate::authorize('view', $customer);
        abort_unless($request->user()->can('customers.update'), 403);

        $data = $request->validate($this->rules(true), [], $this->attributeNames());

        $pivot = $this->pivotAttributes($data);

        $customer->products()->updateExistingPivot($product->id, $pivot);

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Producto {$product->code} actualizado.");
    }

    /**
     * Dedicated catalog view — all products attached to the customer with
     * full pivot metadata. Used when the inline card on the show view is too
     * compact (many products, long notes, etc.).
     */
    public function catalog(Request $request, Customer $customer): View
    {
        Gate::authorize('view', $customer);

        $products = $customer->products()
            ->orderBy('pivot_created_at', 'desc')
            ->get();

        $categories = \App\Models\ProductCategory::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('customers.products_catalog', [
            'customer' => $customer,
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $isUpdate = false): array
    {
        $productRule = $isUpdate ? ['nullable', 'integer', 'exists:products,id'] : ['required', 'integer', 'exists:products,id'];

        return [
            'product_id' => $productRule,
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'price_override' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'purchased_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:purchased_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return [
            'product_id' => 'producto',
            'price_override' => 'precio personalizado',
            'purchased_at' => 'fecha de contratación',
            'expires_at' => 'fecha de vencimiento',
            'notes' => 'notas',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pivotAttributes(array $data): array
    {
        $pivot = [
            'quantity' => (int) ($data['quantity'] ?? 1),
        ];

        foreach (['price_override', 'purchased_at', 'expires_at', 'notes'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $pivot[$field] = $data[$field];
            } else {
                $pivot[$field] = null;
            }
        }

        return $pivot;
    }

    /**
     * Detach a product from a customer. Idempotent: detaching a product that
     * is not attached is a no-op.
     */
    public function destroy(Request $request, Customer $customer, Product $product): RedirectResponse
    {
        Gate::authorize('view', $customer);
        abort_unless($request->user()->can('customers.update'), 403);

        $customer->products()->detach($product->id);

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Producto {$product->code} desvinculado del cliente.");
    }
}