@extends('layouts.app')

@section('title', 'Catálogo de productos — '.$customer->code)
@section('page-title', 'Productos de '.$customer->code)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al cliente
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Cliente</h3></div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-sm-3">
                    <dt class="small text-secondary mb-0">Código</dt>
                    <dd class="mb-2"><code>{{ $customer->code }}</code></dd>
                </div>
                <div class="col-sm-5">
                    <dt class="small text-secondary mb-0">Razón social</dt>
                    <dd class="mb-2">{{ $customer->legal_name }}</dd>
                </div>
                <div class="col-sm-4">
                    <dt class="small text-secondary mb-0">Responsable</dt>
                    <dd class="mb-2">{{ $customer->owner?->name ?? '—' }}</dd>
                </div>
            </div>
        </div>
    </div>

    <div class="card" data-testid="products-catalog">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Productos asociados ({{ $products->count() }})</h3>
                @can('update', $customer)
                    <button type="button"
                            class="btn btn-sm btn-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#product-attach-modal-{{ $customer->getKey() }}"
                            data-testid="btn-attach-from-catalog">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar producto
                    </button>
                @endcan
            </div>
        <div class="card-body p-0 table-responsive">
            @if ($products->isEmpty())
                <p class="text-secondary small px-3 py-3 mb-0" data-testid="catalog-empty">
                    Este cliente no tiene productos asociados todavía.
                </p>
            @else
                <table class="table table-hover align-middle mb-0" data-testid="catalog-table">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th>Tipo</th>
                            <th>Categoría</th>
                            <th class="text-center">Cantidad</th>
                            <th class="text-end">Precio catálogo</th>
                            <th class="text-end">Precio override</th>
                            <th>Contratado</th>
                            <th>Vence</th>
                            <th>Notas</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr data-testid="catalog-row-{{ $product->id }}">
                                <td><code>{{ $product->code }}</code></td>
                                <td>
                                    <strong>{{ $product->name }}</strong>
                                </td>
                                <td>
                                    @if ($product->product_type)
                                        <span class="badge text-bg-secondary">{{ $product->product_type === 'servicio' ? 'Servicio' : 'Producto' }}</span>
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                                <td class="small text-secondary">{{ $product->category?->name ?? '—' }}</td>
                                <td class="text-center">{{ (int) ($product->pivot->quantity ?? 1) }}</td>
                                <td class="text-end text-nowrap text-secondary">
                                    {{ $product->currency_code }} {{ number_format((float) $product->price, 2) }}
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($product->pivot->price_override !== null)
                                        <strong>{{ $product->currency_code }} {{ number_format((float) $product->pivot->price_override, 2) }}</strong>
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">{{ $product->pivot->purchased_at ?: '—' }}</td>
                                <td class="small text-nowrap">{{ $product->pivot->expires_at ?: '—' }}</td>
                                <td class="small" style="max-width: 250px;">{{ $product->pivot->notes ?: '—' }}</td>
                                <td class="text-end text-nowrap">
                                    @can('update', $customer)
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#product-edit-modal-{{ $customer->getKey() }}-{{ $product->id }}"
                                                data-testid="btn-catalog-edit-{{ $product->id }}"
                                                title="Editar">
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

    {{-- Edit modals (shared with the inline card). --}}
    @can('update', $customer)
        @foreach ($products as $product)
            @include('customers._product_edit_modal', ['customer' => $customer, 'product' => $product])
        @endforeach
    @endcan

    {{-- Attach modal (shared with the inline card). --}}
    @include('customers._product_attach_modal', ['customer' => $customer, 'categories' => $categories ?? collect()])
@endsection