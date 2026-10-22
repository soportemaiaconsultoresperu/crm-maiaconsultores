@extends('layouts.app')

@section('title', $activity->title)
@section('page-title', 'Actividad interna')

@section('content')
    @php
        $responsible = $activity->responsible();
        $audienceDetail = match ($activity->audience_type) {
            'roles' => $activity->audienceRoles->pluck('name')->join(', '),
            'users' => $activity->audienceUsers->pluck('name')->join(', '),
            default => null,
        };
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('calendar.index', ['anchor' => $activity->starts_at->format('Y-m-d')]) }}" class="btn btn-outline-secondary">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i> Ver en calendario
        </a>
        @can('update', $activity)
            <a href="{{ route('internal-activities.edit', $activity) }}" class="btn btn-primary">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
        @endcan
        @can('delete', $activity)
            <form method="POST" action="{{ route('internal-activities.destroy', $activity) }}" class="d-inline"
                  onsubmit="return confirm('¿Eliminar esta actividad interna?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar
                </button>
            </form>
        @endcan
    </div>

    <div class="card" data-testid="internal-activity-show">
        <div class="card-header"><h3 class="card-title mb-0">{{ $activity->title }}</h3></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Inicio</dt>
                <dd class="col-sm-9">{{ $activity->starts_at->format('d/m/Y H:i') }}</dd>
                <dt class="col-sm-3">Fin</dt>
                <dd class="col-sm-9">{{ $activity->ends_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                <dt class="col-sm-3">Repetición</dt>
                <dd class="col-sm-9">{{ $activity->recurrenceLabel() ?? 'No se repite' }}</dd>
                <dt class="col-sm-3">Lugar</dt>
                <dd class="col-sm-9">{{ $activity->location ?: '—' }}</dd>
                <dt class="col-sm-3">Audiencia</dt>
                <dd class="col-sm-9">
                    {{ \App\Models\InternalActivity::AUDIENCE_LABELS[$activity->audience_type] ?? $activity->audience_type }}
                    @if ($audienceDetail)
                        <span class="text-secondary">— {{ $audienceDetail }}</span>
                    @endif
                </dd>
                <dt class="col-sm-3">Responsable</dt>
                <dd class="col-sm-9">{{ $responsible?->name ?? '—' }}</dd>
                <dt class="col-sm-3">Participantes</dt>
                <dd class="col-sm-9">{{ $activity->participants->pluck('name')->join(', ') ?: '—' }}</dd>
                <dt class="col-sm-3">Creada por</dt>
                <dd class="col-sm-9">{{ $activity->creator?->name ?? '—' }}</dd>
                <dt class="col-sm-3">Descripción</dt>
                <dd class="col-sm-9">{!! nl2br(e($activity->description ?: '—')) !!}</dd>
            </dl>
        </div>
    </div>
@endsection
