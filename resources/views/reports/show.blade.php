@extends('layouts.app')

@section('title', 'Reporte: ' . $title)
@section('page-title', 'Reportes · ' . $title)

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-3" data-testid="report-header">
        <div>
            <h2 class="h5 mb-1">{{ $title }}</h2>
            <p class="small text-secondary mb-0">{{ $description }}</p>
        </div>
        <a href="{{ $exportUrl }}" class="btn btn-success" data-testid="export-xlsx">
            <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Exportar Excel
        </a>
    </div>

    @include('reports.partials._table', [
        'title' => 'Resultados',
        'headings' => $headings,
        'rows' => $rows,
        'filters' => $filters,
        'emptyMessage' => 'No hay datos para los filtros aplicados.',
    ])
@endsection