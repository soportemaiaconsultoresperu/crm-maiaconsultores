{{--
    Shared "attach product" modal — included from:
    - customers.show        (via the products card)
    - customers.products_catalog  (dedicated catalog view)

    Expected data (props):
        $customer     Customer model (route-bound, must have id).
        $categories   \Illuminate\Support\Collection  (ProductCategory options for the
                        category filter dropdown).

    The modal:
    - Renders a search/type/category filter row that filters the product
      <select> options client-side.
    - Renders the pivot fields (quantity, price_override, dates, notes).
    - Posts to customers.products.store with data-swal-loading.

    The @once @push('scripts') block registers the filter JS once per page.
--}}
@php
    $customerKey = $customer->getKey();

    $availableProducts = \App\Models\Product::query()
        ->where('is_active', true)
        ->whereNotIn('id', $customer->products()->pluck('products.id')->all())
        ->with('category:id,name')
        ->orderBy('name')
        ->get();

    $categories = $categories ?? \App\Models\ProductCategory::query()->orderBy('name')->get(['id', 'name']);
@endphp

@can('update', $customer)
    <x-modal id="product-attach-modal-{{ $customerKey }}" title="Agregar producto al cliente">
        @if ($availableProducts->isNotEmpty())
            <form method="POST"
                  action="{{ route('customers.products.store', $customer) }}"
                  data-testid="product-attach-form"
                  data-swal-loading>
                @csrf

                <div class="row g-2 mb-3" data-testid="product-filters">
                    <div class="col-md-5">
                        <input type="text"
                               id="product-search-{{ $customerKey }}"
                               class="form-control form-control-sm"
                               placeholder="Buscar por código o nombre…"
                               data-product-filter-input>
                        <x-validation-error name="product_id"/>
                    </div>
                    <div class="col-md-3">
                        <select id="product-type-filter-{{ $customerKey }}"
                                class="form-select form-select-sm"
                                data-product-filter-input>
                            <option value="">Todos los tipos</option>
                            <option value="producto">Producto</option>
                            <option value="servicio">Servicio</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select id="product-category-filter-{{ $customerKey }}"
                                class="form-select form-select-sm"
                                data-product-filter-input>
                            <option value="">Todas las categorías</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <x-label :for="'product-id-'.$customerKey" label="Producto" :required="true"/>
                    <select name="product_id"
                            id="product-id-{{ $customerKey }}"
                            class="form-select @error('product_id') is-invalid @enderror"
                            data-testid="product-attach-select"
                            required>
                        <option value="">Seleccione un producto</option>
                        @foreach ($availableProducts as $product)
                            <option value="{{ $product->id }}"
                                    data-type="{{ $product->product_type }}"
                                    data-category="{{ $product->category_id }}"
                                    data-search="{{ \Illuminate\Support\Str::lower($product->code.' '.$product->name) }}"
                                    @selected((string) old('product_id') === (string) $product->id)>
                                {{ $product->code }} — {{ $product->name }}
                                ({{ $product->currency_code }} {{ number_format((float) $product->price, 2) }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <x-text-input name="quantity"
                                      type="number"
                                      label="Cantidad"
                                      :value="old('quantity', 1)"
                                      :required="true"
                                      min="1"
                                      max="99999"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="price_override"
                                      type="number"
                                      step="0.01"
                                      min="0"
                                      label="Precio personalizado"
                                      help="Dejar vacío para usar el precio de catálogo."/>
                    </div>
                    <div class="col-md-5">
                        <x-text-input name="notes"
                                      label="Notas"
                                      placeholder="Contratado en… / Interesado en…"/>
                    </div>
                    <div class="col-md-6">
                        <x-text-input name="purchased_at" type="date" label="Fecha de contratación"/>
                    </div>
                    <div class="col-md-6">
                        <x-text-input name="expires_at" type="date" label="Fecha de vencimiento"/>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <a href="{{ route('products.create') }}" target="_blank" class="small" data-testid="link-create-product">
                        <i class="bi bi-plus-circle me-1"></i> Crear producto nuevo
                    </a>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" data-testid="btn-submit-product-attach">
                            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i> Asociar
                        </button>
                    </div>
                </div>
            </form>
        @else
            <div class="text-center py-4" data-testid="product-attach-empty">
                <i class="bi bi-box-seam text-secondary d-block mb-2" style="font-size: 2rem;" aria-hidden="true"></i>
                <h4 class="h6 mb-2">No hay productos disponibles para asociar</h4>
                <p class="text-secondary small mb-3">
                    Todos los productos activos ya están asociados a este cliente o todavía no hay productos activos cargados.
                </p>
                <a href="{{ route('products.create') }}" class="btn btn-primary" data-testid="btn-create-product-from-attach-empty">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Crear producto
                </a>
            </div>
        @endif
    </x-modal>

    @once
        @push('scripts')
            <script>
                (function () {
                    'use strict';

                    function bindProductFilters(scope) {
                        var search = scope.querySelector('input[data-product-filter-input][id^="product-search-"]');
                        var typeFilter = scope.querySelector('select[data-product-filter-input][id^="product-type-filter-"]');
                        var catFilter = scope.querySelector('select[data-product-filter-input][id^="product-category-filter-"]');
                        var select = scope.querySelector('select[data-testid="product-attach-select"]');
                        if (!select) return;
                        // Fallback: if multiple modals on the same page, scope to the first.
                        search = search || scope.querySelector('input[data-product-filter-input]');
                        typeFilter = typeFilter || scope.querySelector('select[id^="product-type-filter-"]');
                        catFilter = catFilter || scope.querySelector('select[id^="product-category-filter-"]');

                        function apply() {
                            var q = ((search && search.value) || '').toLowerCase().trim();
                            var t = (typeFilter && typeFilter.value) || '';
                            var c = (catFilter && catFilter.value) || '';
                            Array.prototype.forEach.call(select.options, function (opt) {
                                if (!opt.value) { opt.hidden = false; return; }
                                var matchesQ = !q || (opt.dataset.search || '').indexOf(q) !== -1;
                                var matchesT = !t || (opt.dataset.type || '') === t;
                                var matchesC = !c || String(opt.dataset.category || '') === c;
                                opt.hidden = !(matchesQ && matchesT && matchesC);
                            });
                        }

                        [search, typeFilter, catFilter].forEach(function (el) {
                            if (!el) return;
                            el.addEventListener('input', apply);
                            el.addEventListener('change', apply);
                        });
                        apply();
                    }

                    // Auto-bind on every attach modal in the page.
                    function bindAll() {
                        document.querySelectorAll('[data-testid="product-attach-form"]').forEach(function (form) {
                            var scope = form.closest('.modal') || form.parentNode;
                            bindProductFilters(scope);
                        });
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', bindAll);
                    } else {
                        bindAll();
                    }
                })();
            </script>
        @endpush
    @endonce
@endcan

