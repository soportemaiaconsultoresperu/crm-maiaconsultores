@extends('layouts.app')

@section('title', 'Editar usuario')
@section('page-title', 'Editar usuario')

@section('content')
    <div class="row g-3">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('admin.users.update', $user) }}" class="card">
                @csrf
                @method('PUT')
                <div class="card-body">
                    @include('admin.users._form', [
                        'user' => $user,
                        'roles' => $roles,
                        'passwordRequired' => false,
                    ])
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Volver</a>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">Estado</h3></div>
                <div class="card-body">
                    @php($active = $user->is_active)
                    <p class="mb-2">
                        Estado actual: <x-badge-status :status="$active ? 'active' : 'inactive'"/>
                    </p>
                    @if (auth()->user()->can('users.deactivate'))
                        <form method="POST" action="{{ route('admin.users.set-active', $user) }}" class="mb-2">
                            @csrf
                            <input type="hidden" name="is_active" value="{{ $active ? '0' : '1' }}">
                            <input type="hidden" name="reason" value="Cambio de estado desde ficha de edición">
                            <button type="submit" class="btn btn-sm {{ $active ? 'btn-outline-warning' : 'btn-outline-success' }} w-100">
                                {{ $active ? 'Desactivar' : 'Activar' }}
                            </button>
                        </form>
                    @endif
                    @if (auth()->user()->can('users.deactivate') && $active)
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                                    onclick="return confirm('¿Desactivar a {{ $user->name }}?')">
                                Desactivar (POST destroy)
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            @if (auth()->user()->can('users.reset_password'))
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title mb-0">Restablecer contraseña</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
                            @csrf
                            <x-text-input
                                name="password"
                                label="Nueva contraseña"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                            <x-text-input
                                name="password_confirmation"
                                label="Confirma la nueva contraseña"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                            <button type="submit" class="btn btn-warning w-100 mt-2">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Restablecer
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Resumen</h3></div>
                <div class="card-body">
                    <dl class="mb-0 small">
                        <dt>Registrado</dt>
                        <dd>{{ $user->created_at?->format('d/m/Y H:i') }}</dd>
                        <dt>Último acceso</dt>
                        <dd>{{ $user->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}</dd>
                        <dt>Equipos</dt>
                        <dd>{{ $user->teams->pluck('name')->implode(', ') ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection