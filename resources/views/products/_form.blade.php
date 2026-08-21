{{--
    Shared product create/edit form (RF-PROD-001). Expected data:
    $product (null on create), $categories, $currencies, $taxes.
--}}
@php
    $isEdit = $product !== null;
    $types = ['producto' => 'Producto', 'servicio' => 'Servicio'];
@endphp

<form method="POST"
      action="{{ $isEdit ? route('products.update', $product) : route('products.store') }}"
      @if ($isEdit) @method('PUT') @endif
      data-testid="product-form">
    @csrf

    <div class="card">
        <div class="card-header">
            <h3 class="card-title mb-0">{{ $isEdit ? 'Editar producto '.$product->code : 'Nuevo producto' }}</h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @if ($isEdit)
                    <div class="col-md-4">
                        <x-label for="code" label="Código"/>
                        <input type="text" id="code" class="form-control" value="{{ $product->code }}" disabled>
                        <div class="form-text">El código no es editable.</div>
                    </div>
                @endif
                <div class="col-md-4">
                    <x-select name="product_type" label="Tipo" :options="$types" :value="old('product_type', $product?->product_type ?? 'producto')" :required="true"/>
                </div>
                <div class="col-md-8">
                    <x-text-input name="name" label="Nombre" :value="$product?->name" :required="true" maxlength="150"/>
                </div>
                <div class="col-md-4">
                    <x-select name="category_id" label="Categoría"
                              :options="$categories->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
                              :value="$product?->category_id" placeholder="Seleccione"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="price" type="number" label="Precio" :value="$product?->price !== null ? number_format((float) $product->price, 2, '.', '') : ''"
                                  :required="true" step="0.01" min="0"/>
                </div>
                <div class="col-md-4">
                    <x-select name="currency_code" label="Moneda" :required="true"
                              :options="$currencies->mapWithKeys(fn ($c) => [$c->code => $c->code.' — '.$c->name])->all()"
                              :value="$product?->currency_code ?? 'PEN'"/>
                </div>
                <div class="col-md-4">
                    <x-select name="tax_id" label="Impuesto predeterminado"
                              :options="$taxes->mapWithKeys(fn ($t) => [$t->id => $t->name.' ('.number_format((float) $t->rate, 2).'%)'])->all()"
                              :value="$product?->tax_id" placeholder="Seleccione"/>
                </div>
                @if ($isEdit)
                    <div class="col-md-4">
                        <x-label for="is_active" label="Estado"/>
                        <select name="is_active" id="is_active" class="form-select">
                            <option value="1" @if ((string) old('is_active', $product->is_active ? '1' : '0') === '1') selected @endif>Activo</option>
                            <option value="0" @if ((string) old('is_active', $product->is_active ? '1' : '0') === '0') selected @endif>Inactivo</option>
                        </select>
                    </div>
                @endif
                <div class="col-12">
                    <x-label for="description" label="Descripción"/>
                    <textarea name="description" id="description" rows="3" class="form-control @error('description') is-invalid @enderror"
                              placeholder="Detalles del producto o servicio">{{ old('description', $product?->description) }}</textarea>
                    <x-validation-error name="description"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-testid="btn-save-product">
                {{ $isEdit ? 'Guardar cambios' : 'Crear producto' }}
            </button>
            <a href="{{ $isEdit ? route('products.show', $product) : route('products.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>