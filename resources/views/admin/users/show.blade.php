@extends('layouts.app')

@section('title', $user->name)
@section('page-title', $user->name)

@section('content')
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Información del usuario</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Correo</dt>
                        <dd class="col-sm-8">{{ $user->email }}</dd>

                        <dt class="col-sm-4">Roles</dt>
                        <dd class="col-sm-8">
                            @forelse ($user->roles as $role)
                                <span class="badge text-bg-info">{{ $role->name }}</span>
                            @empty
                                <span class="text-secondary">Sin rol asignado</span>
                            @endforelse
                        </dd>

                        <dt class="col-sm-4">Equipos</dt>
                        <dd class="col-sm-8">
                            @forelse ($user->teams as $team)
                                <span class="badge text-bg-secondary">{{ $team->name }}</span>
                            @empty
                                <span class="text-secondary">Sin equipos</span>
                            @endforelse
                        </dd>

                        <dt class="col-sm-4">Equipos supervisados</dt>
                        <dd class="col-sm-8">
                            @forelse ($user->supervisedTeams as $team)
                                <span class="badge text-bg-primary">{{ $team->name }}</span>
                            @empty
                                <span class="text-secondary">No supervisa equipos</span>
                            @endforelse
                        </dd>

                        <dt class="col-sm-4">Estado</dt>
                        <dd class="col-sm-8"><x-badge-status :status="$user->is_active ? 'active' : 'inactive'"/></dd>

                        <dt class="col-sm-4">Registrado</dt>
                        <dd class="col-sm-8">{{ $user->created_at?->format('d/m/Y H:i') }}</dd>

                        <dt class="col-sm-4">Último acceso</dt>
                        <dd class="col-sm-8">{{ $user->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}</dd>
                    </dl>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    @can('update', $user)
                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                        </a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection