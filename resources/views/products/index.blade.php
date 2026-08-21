@extends('layouts.app')

@section('title', 'Productos y servicios')
@section('page-title', 'Productos y servicios')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Product::class)
            <a href="{{ route('products.create') }}" class="btn btn-primary" data-testid="btn-create-product">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo producto
            </a>
        @endcan
        @can('products.import')
            <button type="button" class="btn btn-outline-success"
                    data-bs-toggle="modal" data-bs-target="#modal-import-products">
                <i class="bi bi-upload me-1" aria-hidden="true"></i> Importar Excel
            </button>
            <a href="{{ route('products.template') }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i> Plantilla
            </a>
        @endcan
        @can('products.export')
            <a href="{{ route('products.export', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar
            </a>
        @endcan
    </div>

    @can('products.import')
        @if (session('import_rows') && count(session('import_rows')) > 0)
            <x-alert type="warning" :dismissible="true">
                <strong>Detalle de filas no creadas</strong>
                <ul class="mb-0 mt-1 small">
                    @foreach (session('import_rows') as $row)
                        <li>
                            Fila {{ $row['row'] }} — <em>{{ $row['status'] }}</em>: {{ $row['reason'] }}
                            @if (!empty($row['matched_lead_code'])) ({{ $row['matched_lead_code'] }}) @endif
                        </li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="modal fade" id="modal-import-products" tabindex="-1" aria-labelledby="modal-import-products-title" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="modal-import-products-title">Importar productos desde Excel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary small">
                            Subí un archivo <strong>XLSX, XLS o CSV</strong> con las columnas en este orden:
                            <code>Código, Tipo, Nombre, Categoría, Descripción, Precio, Moneda, Impuesto, Activo</code>.
                            Si no tenés el archivo, <a href="{{ route('products.template') }}">descargá la plantilla</a> primero.
                            Máximo 10&nbsp;MB.
                        </p>
                        <input type="file" name="file"
                               accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                               class="form-control @error('file') is-invalid @enderror" required>
                        @error('file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-upload me-1" aria-hidden="true"></i> Importar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    <x-table title="Catálogo de productos y servicios" data-testid="products-table">
        @slot('filters')
            <form method="GET" action="{{ route('products.index') }}" class="row g-2 align-items-end" data-testid="products-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Código o nombre..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="product_type" class="form-select form-select-sm" aria-label="Tipo">
                        <option value="">Tipo</option>
                        @foreach (['producto' => 'Producto', 'servicio' => 'Servicio'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['product_type'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="category_id" class="form-select form-select-sm" aria-label="Categoría">
                        <option value="">Categoría</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @if ((string) ($filters['category_id'] ?? '') === (string) $category->id) selected @endif>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="currency_code" class="form-select form-select-sm" aria-label="Moneda">
                        <option value="">Moneda</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}" @if (($filters['currency_code'] ?? '') === $currency->code) selected @endif>
                                {{ $currency->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="tax_id" class="form-select form-select-sm" aria-label="Impuesto">
                        <option value="">Impuesto</option>
                        @foreach ($taxes as $tax)
                            <option value="{{ $tax->id }}" @if ((string) ($filters['tax_id'] ?? '') === (string) $tax->id) selected @endif>
                                {{ $tax->name }} ({{ number_format((float) $tax->rate, 2) }}%)
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="is_active" class="form-select form-select-sm" aria-label="Estado">
                        <option value="">Estado</option>
                        @foreach (['1' => 'Activos', '0' => 'Inactivos'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['is_active'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Código</th>
                <th>Tipo</th>
                <th>Nombre</th>
                <th>Categoría</th>
                <th>Precio</th>
                <th>Impuesto</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($products as $product)
                <tr data-testid="product-row">
                    <td><a href="{{ route('products.show', $product) }}" class="fw-medium">{{ $product->code }}</a></td>
                    <td class="text-nowrap">{{ $product->product_type === 'servicio' ? 'Servicio' : 'Producto' }}</td>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->category?->name ?? '—' }}</td>
                    <td class="text-nowrap">{{ $product->currency_code }} {{ number_format((float) $product->price, 2) }}</td>
                    <td class="text-nowrap">{{ $product->tax?->name ?? '—' }}</td>
                    <td>
                        <x-badge-status :status="$product->is_active ? 'active' : 'inactive'"/>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $product)
                            <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay productos o servicios registrados.',
                            'hint' => 'Cree el primer ítem del catálogo.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $products])
        @endslot
    </x-table>
@endsection