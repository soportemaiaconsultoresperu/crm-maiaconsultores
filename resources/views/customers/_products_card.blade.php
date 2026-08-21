{{--
    Customer ↔ Products panel — included from the customer show view.
    Renders a card with the list of products associated with the customer
    and an "Add product" button that opens a modal to attach a new one.

    Features:
    - Per-row edit modal (notes, quantity, price_override, purchased_at, expires_at)
    - Per-row detach via <x-swal-confirm>
    - Attach modal is extracted to customers/_product_attach_modal.blade.php
      and included below (so the dedicated catalog view can share it).
    - "Ver catálogo completo" link to the dedicated catalog view

    Inputs (props):
        $customer   Customer model (route-bound, must have id).
        $categories \Illuminate\Support\Collection  (ProductCategory options for filter)
--}}
@php
    $customerProducts = $customer->products()->orderBy('pivot_created_at', 'desc')->get();
    $attachedIds = $customerProducts->pluck('id')->all();

    // Active products the customer does NOT already have attached.
    $availableProducts = \App\Models\Product::query()
        ->where('is_active', true)
        ->when(! empty($attachedIds), fn ($q) => $q->whereNotIn('id', $attachedIds))
        ->with('category:id,name')
        ->orderBy('name')
        ->get();

    // Categories list (for the type/category filter in the attach modal).
    $categories = $categories ?? \App\Models\ProductCategory::query()->orderBy('name')->get(['id', 'name']);
@endphp

<div class="card mt-3" data-testid="products-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Productos</h3>
        <div class="d-flex gap-2">
            <a href="{{ route('customers.products.catalog', $customer) }}" class="btn btn-sm btn-outline-secondary" data-testid="btn-view-catalog">
                <i class="bi bi-grid me-1" aria-hidden="true"></i> Ver catálogo
            </a>
            @can('update', $customer)
                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#product-attach-modal-{{ $customer->getKey() }}"
                        data-testid="btn-attach-product">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar producto
                </button>
            @endcan
        </div>
    </div>
    <div class="card-body p-0 table-responsive">
        @if ($customerProducts->isEmpty())
            <p class="text-secondary small px-3 py-3 mb-0" data-testid="products-empty">
                Sin productos asociados. Agregue los productos que el cliente tiene contratados o le interesan.
            </p>
        @else
            <table class="table table-hover align-middle mb-0" data-testid="products-table">
                <thead class="table-light">
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th class="text-center">Cant.</th>
                        <th class="text-end">Precio</th>
                        <th>Contratado</th>
                        <th>Vence</th>
                        <th>Notas</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customerProducts as $product)
                        <tr data-testid="product-row">
                            <td><code>{{ $product->code }}</code></td>
                            <td>
                                <strong>{{ $product->name }}</strong>
                                @if ($product->product_type)
                                    <span class="badge text-bg-secondary ms-1">{{ $product->product_type === 'servicio' ? 'Servicio' : 'Producto' }}</span>
                                @endif
                            </td>
                            <td class="small text-secondary">{{ $product->category?->name ?? '—' }}</td>
                            <td class="text-center">{{ (int) ($product->pivot->quantity ?? 1) }}</td>
                            <td class="text-end text-nowrap">
                                @if ($product->pivot->price_override !== null)
                                    <strong>{{ $product->currency_code }} {{ number_format((float) $product->pivot->price_override, 2) }}</strong>
                                    <div class="small text-secondary text-decoration-line-through">
                                        cat. {{ number_format((float) $product->price, 2) }}
                                    </div>
                                @else
                                    {{ $product->currency_code }} {{ number_format((float) $product->price, 2) }}
                                @endif
                            </td>
                            <td class="small text-nowrap">{{ $product->pivot->purchased_at ?: '—' }}</td>
                            <td class="small text-nowrap">{{ $product->pivot->expires_at ?: '—' }}</td>
                            <td class="small text-secondary" style="max-width: 200px;">
                                <span title="{{ $product->pivot->notes }}">{{ \Illuminate\Support\Str::limit($product->pivot->notes, 50) ?: '—' }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                @can('update', $customer)
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary me-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#product-edit-modal-{{ $customer->getKey() }}-{{ $product->id }}"
                                            data-testid="btn-edit-product-{{ $product->id }}"
                                            title="Editar asociación">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <x-swal-confirm
                                        :action="route('customers.products.destroy', [$customer, $product])"
                                        method="DELETE"
                                        :title="¿Desvincular '{{ $product->name }}'?"
                                        :text="'El producto '.$product->code.' dejará de estar asociado al cliente '.$customer->code.'. Puede volver a asociarlo luego.'"
                                        type="warning"
                                        confirm-text="Sí, desvincular"
                                        button-class="btn-sm btn-outline-danger"
                                        title="Desvincular"
                                        class="d-inline">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    </x-swal-confirm>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

{{-- ============================================================ --}}
{{-- EDIT MODAL — one per attached product (shared with the catalog view) --}}
{{-- ============================================================ --}}
@can('update', $customer)
    @foreach ($customerProducts as $product)
        @include('customers._product_edit_modal', ['customer' => $customer, 'product' => $product])
    @endforeach
@endcan

{{-- ============================================================ --}}
{{-- ATTACH MODAL — extracted to customers/_product_attach_modal.blade.php --}}
{{-- so it can be shared with the dedicated catalog view. --}}
{{-- ============================================================ --}}
@include('customers._product_attach_modal', ['customer' => $customer, 'categories' => $categories])