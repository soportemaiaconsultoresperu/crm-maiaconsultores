@extends('layouts.app')

@section('title', 'Importar prospectos')
@section('page-title', 'Importar prospectos')

@section('content')
    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">Importación desde Excel</h3></div>
        <div class="card-body">
            <form method="POST" action="{{ route('leads.import.process') }}"
                  enctype="multipart/form-data" class="row g-3" data-testid="import-form">
                @csrf

                <div class="col-md-6">
                    <x-label for="file" label="Archivo Excel" :required="true"/>
                    <input type="file" name="file" id="file" accept=".xlsx,.xls"
                           class="form-control @error('file') is-invalid @enderror" required>
                    <x-validation-error name="file"/>
                    <div class="form-text">
                        Formatos .xlsx o .xls, máximo 5&nbsp;MB. Encabezados de plantilla (fila 1):
                        Tipo de persona, Nombre, Apellidos, Razón social, Cargo, Tipo de documento,
                        Número de documento, Teléfono, WhatsApp, Correo electrónico, Dirección,
                        Código de distrito (ubigeo), Nivel de interés, Observaciones.
                        (También se aceptan los encabezados en snake_case de la versión anterior.)
                    </div>
                    <div class="form-text">
                        Las filas duplicadas (documento, correo o teléfono ya existentes) se
                        omiten y se reportan; nunca se actualizan automáticamente (ADR-003).
                    </div>
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i> Importar
                    </button>
                    <a href="{{ route('leads.template') }}" class="btn btn-outline-success" data-testid="download-template">
                        <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i> Descargar plantilla
                    </a>
                    <a href="{{ route('leads.index') }}" class="btn btn-outline-secondary">Volver</a>
                </div>
            </form>
        </div>
    </div>

    @isset($result)
        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title mb-0">Resultado de la importación</h3></div>
            <div class="card-body">
                <div class="row g-3 mb-3" data-testid="import-summary">
                    <div class="col-sm-4">
                        <div class="border rounded p-3 text-center">
                            <p class="h5 mb-0 text-success">{{ $result->created }}</p>
                            <p class="small text-secondary mb-0">Creados</p>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="border rounded p-3 text-center">
                            <p class="h5 mb-0 text-warning">{{ $result->skipped }}</p>
                            <p class="small text-secondary mb-0">Omitidos (duplicados)</p>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="border rounded p-3 text-center">
                            <p class="h5 mb-0 text-danger">{{ $result->invalid }}</p>
                            <p class="small text-secondary mb-0">Inválidos</p>
                        </div>
                    </div>
                </div>

                @if (count($result->rows) > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle" data-testid="import-report">
                            <thead class="table-light">
                                <tr>
                                    <th>Fila</th>
                                    <th>Estado</th>
                                    <th>Motivo</th>
                                    <th>Lead coincidente</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result->rows as $row)
                                    <tr>
                                        <td>{{ $row['row'] }}</td>
                                        <td>
                                            @if ($row['status'] === 'skipped')
                                                <span class="badge text-bg-warning">Omitido</span>
                                            @else
                                                <span class="badge text-bg-danger">Inválido</span>
                                            @endif
                                        </td>
                                        <td>{{ $row['reason'] }}</td>
                                        <td>{{ $row['matched_lead_code'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-alert type="success">Todas las filas se procesaron sin errores.</x-alert>
                @endif
            </div>
        </div>
    @endisset
@endsection
