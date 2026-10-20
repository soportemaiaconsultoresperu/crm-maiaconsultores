@extends('layouts.app')

@section('title', 'Actividades internas')
@section('page-title', 'Actividades internas')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <ul class="nav nav-pills me-auto">
            <li class="nav-item">
                <a class="nav-link {{ $scope === 'upcoming' ? 'active' : '' }}" href="{{ route('internal-activities.index') }}">Próximas</a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $scope === 'past' ? 'active' : '' }}" href="{{ route('internal-activities.index', ['scope' => 'past']) }}">Pasadas</a>
            </li>
        </ul>
        <a href="{{ route('calendar.index', ['layer' => 'internal']) }}" class="btn btn-outline-secondary">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i> Ver en calendario
        </a>
        <a href="{{ route('internal-activities.create') }}" class="btn btn-primary" data-testid="btn-new-internal-activity">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva actividad interna
        </a>
    </div>

    <x-table title="Actividades internas" data-testid="internal-activities-list">
        @slot('headers')
            <tr>
                <th>Fecha y hora</th>
                <th>Título</th>
                <th>Lugar</th>
                <th>Audiencia</th>
                <th>Participantes</th>
                <th class="text-end">Acción</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($activities as $activity)
                <tr data-testid="internal-activity-row">
                    <td class="text-nowrap">{{ $activity->starts_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $activity->title }}</td>
                    <td>{{ $activity->location ?: '—' }}</td>
                    <td>{{ \App\Models\InternalActivity::AUDIENCE_LABELS[$activity->audience_type] ?? $activity->audience_type }}</td>
                    <td>{{ $activity->participants->count() }}</td>
                    <td class="text-end">
                        <a href="{{ route('internal-activities.show', $activity) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        @include('layouts.partials.empty-state', ['message' => 'No hay actividades internas.'])
                    </td>
                </tr>
            @endforelse
        @endslot
    </x-table>

    {{ $activities->links() }}
@endsection
