@extends('layouts.app')

@section('title', 'Roles')
@section('page-title', 'Roles y permisos')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('roles.manage')
            <a href="{{ route('admin.roles.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo rol
            </a>
        @endcan
    </div>

    @if ($errors->any())
        <x-alert type="error">{{ $errors->first() }}</x-alert>
    @endif

    <x-table title="Roles del sistema">
        @slot('filters')
            <form method="GET" action="{{ route('admin.roles.index') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Nombre del rol..." aria-label="Buscar">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Nombre</th>
                <th>Permisos</th>
                <th>Usuarios</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($roles as $role)
                <tr>
                    <td><strong>{{ $role->name }}</strong></td>
                    <td>{{ $role->permissions_count }}</td>
                    <td>{{ $role->users_count }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('admin.roles.show', $role) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('roles.manage')
                            <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                            @if (! in_array($role->name, $protectedRoles, true))
                                <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('¿Eliminar el rol {{ $role->name }}?')">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                    Eliminar</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        @include('layouts.partials.empty-state', ['message' => 'No hay roles que coincidan.'])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $roles])
        @endslot
    </x-table>
@endsection