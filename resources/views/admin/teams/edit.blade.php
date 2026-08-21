@extends('layouts.app')

@section('title', 'Editar equipo')
@section('page-title', 'Editar equipo — '.$team->name)

@section('content')
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('admin.teams.update', $team) }}" class="card">
                @csrf
                @method('PUT')
                <div class="card-body">
                    @include('admin.teams._form', [
                        'team' => $team,
                        'supervisors' => $supervisors,
                    ])
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('admin.teams.index') }}" class="btn btn-outline-secondary">Volver</a>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>

            @if ($team->is_active)
                <div class="card mt-3 border-warning">
                    <div class="card-header"><h3 class="card-title mb-0 text-warning">Desactivar equipo</h3></div>
                    <div class="card-body">
                        <p class="small text-secondary">Los equipos desactivados no modifican el alcance de datos existente, pero dejan de mostrarse en la lista principal.</p>
                        <form method="POST" action="{{ route('admin.teams.destroy', $team) }}">
                            @csrf
                            <x-text-input name="reason" label="Motivo" required/>
                            <button type="submit" class="btn btn-warning mt-2"
                                    onclick="return confirm('¿Desactivar el equipo {{ $team->name }}?')">
                                Desactivar equipo
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">Miembros</h3></div>
                <div class="card-body">
                    @if ($errors->has('member'))
                        <x-alert type="error">{{ $errors->first('member') }}</x-alert>
                    @endif
                    <ul class="list-group list-group-flush mb-3">
                        @forelse ($team->members as $member)
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <strong>{{ $member->name }}</strong>
                                    <div class="small text-secondary">{{ $member->email }}</div>
                                </div>
                                @if ($member->id !== $team->supervisor_id)
                                    <form method="POST" action="{{ route('admin.teams.remove-member', [$team, $member]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('¿Quitar a {{ $member->name }} del equipo?')">
                                            Quitar
                                        </button>
                                    </form>
                                @else
                                    <span class="badge text-bg-info">Supervisor</span>
                                @endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-secondary small">Sin miembros.</li>
                        @endforelse
                    </ul>

                    <form method="POST" action="{{ route('admin.teams.add-member', $team) }}" class="d-flex gap-2">
                        @csrf
                        <select name="user_id" class="form-select form-select-sm" required>
                            <option value="">Agregar miembro…</option>
                            @foreach ($memberCandidates as $candidate)
                                @if (! $team->members->contains($candidate->id))
                                    <option value="{{ $candidate->id }}">{{ $candidate->name }} — {{ $candidate->email }}</option>
                                @endif
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Agregar</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Cambiar supervisor</h3></div>
                <div class="card-body">
                    @php($supervisorOptions = $memberCandidates->pluck('name', 'id')->all())
                    <form method="POST" action="{{ url('admin/teams/'.$team->id.'/set-supervisor/0') }}" id="set-supervisor-form">
                        @csrf
                        <x-select
                            name="user_id"
                            label="Nuevo supervisor"
                            :options="$supervisorOptions"
                            :value="$team->supervisor_id"
                            placeholder="Selecciona un supervisor"
                        />
                        <button type="submit" class="btn btn-primary mt-2">Actualizar supervisor</button>
                    </form>
                    <p class="small text-secondary mt-2">El nuevo supervisor debe ser un usuario activo del sistema.</p>
                </div>
            </div>
        </div>
    </div>
@endsection