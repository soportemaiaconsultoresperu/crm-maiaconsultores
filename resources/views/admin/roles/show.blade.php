@extends('layouts.app')

@section('title', 'Rol — '.ucfirst($role->name))
@section('page-title', 'Rol — '.ucfirst($role->name))

@section('content')
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2">
                    <h3 class="card-title mb-0">Lo que puede hacer este rol</h3>
                    <span class="badge rounded-pill text-bg-light border ms-auto">{{ $role->permissions->count() }} permisos</span>
                </div>
                @if ($fullAccess)
                    <div class="card-body">
                        <div class="alert alert-info d-flex gap-3 mb-0" role="note">
                            <i class="bi bi-shield-check fs-3" aria-hidden="true"></i>
                            <div>
                                <h4 class="h6 mb-1">Acceso total: el administrador puede todo, siempre</h4>
                                <p class="mb-0 small">El sistema concede a este rol todas las acciones sin revisar permisos individuales.</p>
                            </div>
                        </div>
                    </div>
                @else
                    <ul class="list-group list-group-flush">
                        @forelse ($summary as $item)
                            <li class="list-group-item d-flex gap-2">
                                <i class="bi {{ $item['icon'] }} text-secondary mt-1" aria-hidden="true"></i>
                                <div><strong>{{ $item['name'] }}:</strong> {{ $item['actions'] }}</div>
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">Sin permisos asignados.</li>
                        @endforelse
                    </ul>
                @endif
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Volver</a>
                    @can('roles.manage')
                        <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-outline-primary">
                            <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                        </a>
                    @endcan
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Usuarios con este rol ({{ $role->users->count() }})</h3></div>
                <ul class="list-group list-group-flush">
                    @forelse ($role->users as $user)
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span>{{ $user->name }}</span>
                            <span class="small text-secondary text-truncate">{{ $user->email }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-secondary small">Ningún usuario tiene este rol.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
