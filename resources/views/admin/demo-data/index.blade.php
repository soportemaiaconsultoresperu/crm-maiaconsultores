@extends('layouts.app')

@section('title', 'Datos de demostración')
@section('page-title', 'Datos de demostración')

@section('content')
    <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Datos de demostración</h1>
            <p class="text-muted mb-0">Sistema seguro para cargar, restablecer y eliminar datos ficticios de presentación.</p>
        </div>
        @if ($activeBatch)
            <span class="badge text-bg-warning">Demo activo: {{ $activeBatch->uuid }}</span>
        @else
            <span class="badge text-bg-secondary">Sin lote demo activo</span>
        @endif
    </div>

    <div class="container-fluid px-0 py-2">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="alert alert-warning d-flex gap-3 align-items-start">
            <i class="bi bi-exclamation-triangle-fill fs-4" aria-hidden="true"></i>
            <div>
                <strong>Advertencia de datos reales.</strong>
                Actualmente se detectan {{ $realDataCount }} registros comerciales reales/no-demo en módulos principales.
                La generación demo no los vincula ni los elimina; el reset/delete opera exclusivamente con el ledger central.
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Cargar demostración completa</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Crea asesores demo inactivos, prospectos, clientes, oportunidades, actividades, cotizaciones, documentos placeholder, campañas visuales y casos de soporte.</p>
                        <form method="POST" action="{{ route('admin.demo-data.load') }}" data-swal-loading="Generando datos demo...">
                            @csrf
                            <button class="btn btn-primary w-100" type="submit" data-swal-confirm data-swal-title="Cargar datos de demostración completos?">
                                <i class="bi bi-database-add me-1"></i> Cargar demo completa
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Cargar módulos seleccionados</h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.demo-data.index') }}" class="mb-3">
                            <div class="row g-2">
                                @foreach ($modules as $module)
                                    <div class="col-sm-6 col-xl-4">
                                        <label class="form-check border rounded px-3 py-2 h-100">
                                            <input class="form-check-input" type="checkbox" name="modules[]" value="{{ $module }}" @checked(in_array($module, $preview['requested'], true))>
                                            <span class="form-check-label">{{ ucfirst(str_replace('_', ' ', $module)) }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <button class="btn btn-outline-secondary mt-3" type="submit">Previsualizar dependencias</button>
                        </form>

                        <div class="alert alert-info">
                            <strong>Se crearán:</strong> {{ implode(', ', $preview['expanded']) }}.
                            @if (count($preview['dependencies']) > 0)
                                <br><strong>Dependencias agregadas:</strong> {{ implode(', ', $preview['dependencies']) }}.
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.demo-data.load-modules') }}" data-swal-loading="Generando módulos demo...">
                            @csrf
                            @foreach ($preview['requested'] as $module)
                                <input type="hidden" name="modules[]" value="{{ $module }}">
                            @endforeach
                            <button class="btn btn-success" type="submit" data-swal-confirm data-swal-title="Cargar módulos seleccionados y sus dependencias demo?">Cargar módulos seleccionados</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @if ($activeBatch)
            <div class="card shadow-sm mt-4 border-warning">
                <div class="card-header bg-warning-subtle">
                    <h2 class="h5 mb-0">Lote activo</h2>
                </div>
                <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3">
                    <div>
                        <div><strong>UUID:</strong> {{ $activeBatch->uuid }}</div>
                        <div><strong>Estado:</strong> {{ $activeBatch->status }}</div>
                        <div><strong>Módulos:</strong> {{ implode(', ', $activeBatch->modules ?? []) }}</div>
                    </div>
                    <div class="d-flex gap-2 align-items-start">
                        <form method="POST" action="{{ route('admin.demo-data.reset', $activeBatch) }}" data-swal-loading="Restableciendo demo...">
                            @csrf
                            <button class="btn btn-outline-warning" type="submit" data-swal-confirm data-swal-title="Restablecer este lote?" data-swal-text="Se eliminarán sus datos demo y se generará un nuevo lote.">Restablecer</button>
                        </form>
                        <form method="POST" action="{{ route('admin.demo-data.destroy', $activeBatch) }}" data-swal-loading="Eliminando demo...">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger" type="submit" data-swal-confirm data-swal-title="Eliminar definitivamente los datos de este lote demo?">Eliminar</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        <div class="card shadow-sm mt-4">
            <div class="card-header">
                <h2 class="h5 mb-0">Historial de lotes</h2>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>UUID</th>
                            <th>Estado</th>
                            <th>Módulos</th>
                            <th>Registros</th>
                            <th>Inicio</th>
                            <th>Fin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr>
                                <td><code>{{ $batch->uuid }}</code></td>
                                <td><span class="badge text-bg-{{ $batch->isActive() ? 'warning' : 'secondary' }}">{{ $batch->status }}</span></td>
                                <td>{{ implode(', ', $batch->modules ?? []) }}</td>
                                <td>{{ $batch->records_count }}</td>
                                <td>{{ optional($batch->started_at)->format('d/m/Y H:i') }}</td>
                                <td>{{ optional($batch->finished_at)->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Aún no hay lotes demo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $batches->links() }}</div>
        </div>
    </div>
@endsection
