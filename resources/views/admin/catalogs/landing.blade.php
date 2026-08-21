@extends('layouts.app')

@section('title', 'Catálogos')
@section('page-title', 'Catálogos del sistema')

@section('content')
    @if (session('status'))
        <x-alert type="success" :dismissible="true">{{ session('status') }}</x-alert>
    @endif

    <p class="text-secondary">
        Cada catálogo alimenta los dropdowns y selectores del resto del CRM.
        Los registros se <strong>desactivan</strong>, nunca se borran, para preservar el histórico.
        Las acciones de crear, editar, activar y desactivar requieren el permiso
        <code>catalogs.manage</code>; solo lectura requiere <code>catalogs.view</code>.
    </p>

    <div class="row g-3">
        @foreach ($catalogs as $catalog)
            <div class="col-md-6 col-lg-4">
                <a href="{{ route('admin.catalogs.index', ['kind' => $catalog['kind']]) }}"
                   class="text-decoration-none">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi {{ $catalog['icon'] }} fs-3 text-primary me-2" aria-hidden="true"></i>
                                <h5 class="card-title mb-0">{{ $catalog['label'] }}</h5>
                            </div>
                            <p class="card-text text-secondary flex-grow-1">
                                {{ $catalog['description'] }}
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge text-bg-light">
                                    {{ $catalog['active'] }} activos
                                    @if ($catalog['inactive'] > 0)
                                        · {{ $catalog['inactive'] }} inactivos
                                    @endif
                                </span>
                                <span class="text-primary small">
                                    Administrar <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@endsection
