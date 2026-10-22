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
                <th>Rol</th>
                <th>Acceso a módulos</th>
                <th class="text-center">Usuarios</th>
                <th class="text-center">Permisos</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($roles as $role)
                <tr>
                    <td>
                        <strong>{{ ucfirst($role->name) }}</strong>
                        @if (in_array($role->name, $protectedRoles, true))
                            <span class="badge text-bg-light border ms-1" title="Rol del sistema: no puede eliminarse">Del sistema</span>
                        @endif
                    </td>
                    <td>
                        @if ($role->name === \App\Support\Permissions\PermissionCatalog::FULL_ACCESS_ROLE)
                            <span class="badge text-bg-success"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Acceso total</span>
                        @else
                            @php($moduleNames = collect($summaries[$role->id] ?? [])->pluck('name'))
                            @forelse ($moduleNames->take(6) as $moduleName)
                                <span class="badge rounded-pill text-bg-light border fw-normal">{{ $moduleName }}</span>
                            @empty
                                <span class="small text-secondary">Sin permisos asignados</span>
                            @endforelse
                            @if ($moduleNames->count() > 6)
                                <span class="badge rounded-pill text-bg-secondary fw-normal" title="{{ $moduleNames->slice(6)->implode(', ') }}">+{{ $moduleNames->count() - 6 }}</span>
                            @endif
                        @endif
                    </td>
                    <td class="text-center">{{ $role->users_count }}</td>
                    <td class="text-center">{{ $role->permissions_count }}</td>
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
                                            onclick="return confirm('¿Eliminar el rol {{ ucfirst($role->name) }}?')">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                    Eliminar</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
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