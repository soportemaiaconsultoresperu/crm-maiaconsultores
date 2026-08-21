@extends('layouts.app')

@section('title', 'Equipos')
@section('page-title', 'Equipos')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Team::class)
            <a href="{{ route('admin.teams.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo equipo
            </a>
        @endcan
    </div>

    <x-table title="Listado de equipos">
        @slot('filters')
            <form method="GET" action="{{ route('admin.teams.index') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Nombre del equipo..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="supervisor_id" class="form-select form-select-sm" aria-label="Supervisor">
                        <option value="">Cualquier supervisor</option>
                        @foreach ($supervisors as $supervisor)
                            <option value="{{ $supervisor->id }}" @if ((string) ($filters['supervisor_id'] ?? '') === (string) $supervisor->id) selected @endif>{{ $supervisor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="is_active" class="form-select form-select-sm" aria-label="Estado">
                        <option value="">Activos e inactivos</option>
                        <option value="1" @if (($filters['is_active'] ?? '') === '1') selected @endif>Solo activos</option>
                        <option value="0" @if (($filters['is_active'] ?? '') === '0') selected @endif>Solo inactivos</option>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('admin.teams.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Nombre</th>
                <th>Supervisor</th>
                <th>Miembros</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($teams as $team)
                <tr>
                    <td><a href="{{ route('admin.teams.show', $team) }}" class="fw-medium">{{ $team->name }}</a></td>
                    <td>{{ $team->supervisor?->name ?? '—' }}</td>
                    <td>{{ $team->members_count ?? $team->members->count() }}</td>
                    <td><x-badge-status :status="$team->is_active ? 'active' : 'inactive'"/></td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('admin.teams.show', $team) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $team)
                            <a href="{{ route('admin.teams.edit', $team) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay equipos registrados.',
                            'hint' => 'Crea un equipo para organizar vendedores.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $teams])
        @endslot
    </x-table>
@endsection