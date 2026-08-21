@extends('layouts.app')

@section('title', 'Reportes')
@section('page-title', 'Reportes')

@section('content')
    <x-table id="reports-index" title="Reportes disponibles">
        <x-slot:filters></x-slot:filters>
        <x-slot:headers>
            <tr>
                <th>Reporte</th>
                <th>Descripción</th>
                <th class="text-end">Acciones</th>
            </tr>
        </x-slot:headers>
        <x-slot:rows>
            @forelse ($reports as $slug => $meta)
                <tr data-testid="report-row-{{ $slug }}">
                    <td class="fw-medium">{{ $meta['title'] }}</td>
                    <td class="small text-secondary">{{ $meta['description'] }}</td>
                    <td class="text-end">
                        <a href="{{ $meta['url'] }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye" aria-hidden="true"></i> Ver
                        </a>
                        <a href="{{ $meta['export_url'] }}" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Excel
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center text-secondary small py-3">No hay reportes configurados.</td>
                </tr>
            @endforelse
        </x-slot:rows>
    </x-table>
@endsection