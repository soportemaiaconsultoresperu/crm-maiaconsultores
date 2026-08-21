@extends('layouts.app')

@section('title', 'Usuarios')
@section('page-title', 'Usuarios')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\User::class)
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo usuario
            </a>
        @endcan
    </div>

    <x-table title="Listado de usuarios">
        @slot('filters')
            <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Nombre o correo..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="role" class="form-select form-select-sm" aria-label="Rol">
                        <option value="">Todos los roles</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @if (($filters['role'] ?? '') === $role) selected @endif>{{ ucfirst($role) }}</option>
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
                    <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Equipos</th>
                <th>Último acceso</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($users as $user)
                <tr>
                    <td><a href="{{ route('admin.users.show', $user) }}" class="fw-medium">{{ $user->name }}</a></td>
                    <td>{{ $user->email }}</td>
                    <td>
                        @forelse ($user->roles as $role)
                            <span class="badge text-bg-info">{{ $role->name }}</span>
                        @empty
                            <span class="text-secondary small">Sin rol</span>
                        @endforelse
                    </td>
                    <td>{{ $user->teams->pluck('name')->implode(', ') ?: '—' }}</td>
                    <td class="text-nowrap">{{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td><x-badge-status :status="$user->is_active ? 'active' : 'inactive'"/></td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $user)
                            <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay usuarios que coincidan con el filtro.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $users])
        @endslot
    </x-table>
@endsection