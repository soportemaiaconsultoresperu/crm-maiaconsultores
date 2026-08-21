@extends('layouts.app')

@section('title', 'Producto '.$product->code)
@section('page-title', $product->code)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('update', $product)
            <a href="{{ route('products.edit', $product) }}" class="btn btn-primary">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
        @endcan
        @can('delete', $product)
            <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#product-deactivate-modal">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
            </button>
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Datos del producto</h3>
                    <x-badge-status :status="$product->is_active ? 'active' : 'inactive'"/>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Código</dt>
                        <dd class="col-sm-8" data-testid="product-code">{{ $product->code }}</dd>

                        <dt class="col-sm-4">Tipo</dt>
                        <dd class="col-sm-8">{{ $product->product_type === 'servicio' ? 'Servicio' : 'Producto' }}</dd>

                        <dt class="col-sm-4">Nombre</dt>
                        <dd class="col-sm-8">{{ $product->name }}</dd>

                        <dt class="col-sm-4">Categoría</dt>
                        <dd class="col-sm-8">{{ $product->category?->name ?? '—' }}</dd>

                        <dt class="col-sm-4">Precio</dt>
                        <dd class="col-sm-8">{{ $product->currency_code }} {{ number_format((float) $product->price, 2) }}</dd>

                        <dt class="col-sm-4">Impuesto predeterminado</dt>
                        <dd class="col-sm-8">
                            {{ $product->tax?->name ?? '—' }}
                            @if ($product->tax)
                                ({{ number_format((float) $product->tax->rate, 2) }}%)
                            @endif
                        </dd>

                        @if ($product->description)
                            <dt class="col-sm-4">Descripción</dt>
                            <dd class="col-sm-8">{{ $product->description }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Auditoría</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Creado</dt>
                        <dd class="col-sm-8">{{ $product->created_at?->format('d/m/Y H:i') }}</dd>
                        <dt class="col-sm-4">Actualizado</dt>
                        <dd class="col-sm-8">{{ $product->updated_at?->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    @can('delete', $product)
        <x-modal id="product-deactivate-modal" title="Desactivar producto">
            <form method="POST" action="{{ route('products.destroy', $product) }}" data-testid="deactivate-form">
                @csrf
                <p class="text-secondary">
                    El producto {{ $product->code }} se desactivará; nunca se elimina físicamente
                    (RF-PROD-002). Indique el motivo:
                </p>
                <div class="mb-3">
                    <x-label for="reason" label="Motivo" :required="true"/>
                    <textarea name="reason" id="reason" rows="3" class="form-control @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                    <x-validation-error name="reason"/>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Desactivar</button>
                </div>
            </form>
        </x-modal>
    @endcan
@endsection