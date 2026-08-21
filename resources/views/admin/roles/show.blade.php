@extends('layouts.app')

@section('title', 'Rol — '.$role->name)
@section('page-title', 'Rol — '.$role->name)

@section('content')
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Permisos ({{ $role->permissions->count() }})</h3></div>
                <ul class="list-group list-group-flush">
                    @forelse ($role->permissions as $permission)
                        <li class="list-group-item small">{{ $permission->name }}</li>
                    @empty
                        <li class="list-group-item text-secondary small">Sin permisos asignados.</li>
                    @endforelse
                </ul>
                <div class="card-footer d-flex justify-content-end gap-2">
                    @can('roles.manage')
                        <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                        </a>
                    @endcan
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Usuarios ({{ $role->users->count() }})</h3></div>
                <ul class="list-group list-group-flush">
                    @forelse ($role->users as $user)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $user->name }}</span>
                            <span class="small text-secondary">{{ $user->email }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-secondary small">Ningún usuario tiene este rol.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection