@extends('layouts.app')

@section('title', $team->name)
@section('page-title', 'Equipo — '.$team->name)

@section('content')
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Información del equipo</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Nombre</dt>
                        <dd class="col-sm-8">{{ $team->name }}</dd>

                        <dt class="col-sm-4">Supervisor</dt>
                        <dd class="col-sm-8">{{ $team->supervisor?->name ?? '—' }}</dd>

                        <dt class="col-sm-4">Estado</dt>
                        <dd class="col-sm-8"><x-badge-status :status="$team->is_active ? 'active' : 'inactive'"/></dd>

                        <dt class="col-sm-4">Miembros</dt>
                        <dd class="col-sm-8">{{ $team->members->count() }}</dd>
                    </dl>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    @can('update', $team)
                        <a href="{{ route('admin.teams.edit', $team) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                        </a>
                    @endcan
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Miembros</h3></div>
                <ul class="list-group list-group-flush">
                    @forelse ($team->members as $member)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>{{ $member->name }}</strong>
                                <div class="small text-secondary">{{ $member->email }}</div>
                            </div>
                            @if ($member->id === $team->supervisor_id)
                                <span class="badge text-bg-info">Supervisor</span>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-secondary small">Sin miembros.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection