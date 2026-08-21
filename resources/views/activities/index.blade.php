@extends('layouts.app')

@section('title', 'Actividades')
@section('page-title', 'Actividades')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Activity::class)
            <a href="{{ route('activities.create') }}" class="btn btn-primary" data-testid="btn-create-activity">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva actividad
            </a>
        @endcan
        <a href="{{ route('calendar.index') }}" class="btn btn-outline-secondary" data-testid="btn-calendar">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i> Calendario
        </a>
    </div>

    <x-table title="Listado de actividades" data-testid="activities-table">
        @slot('filters')
            <form method="GET" action="{{ route('activities.index') }}" class="row g-2 align-items-end" data-testid="activities-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Título, descripción o resultado..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="status" class="form-select form-select-sm" aria-label="Estado">
                        <option value="">Estado</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @if (($filters['status'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="type_id" class="form-select form-select-sm" aria-label="Tipo">
                        <option value="">Tipo</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}" @if ((string) ($filters['type_id'] ?? '') === (string) $type->id) selected @endif>{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="subject_type" class="form-select form-select-sm" aria-label="Sujeto">
                        <option value="">Sujeto</option>
                        @foreach ($subjectTypes as $value => $label)
                            <option value="{{ $value }}" @if (($filters['subject_type'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (auth()->user()->can('activities.view.any') || auth()->user()->can('activities.view.team'))
                    <div class="col-auto">
                        <select name="owner_id" class="form-select form-select-sm" aria-label="Responsable">
                            <option value="">Responsable</option>
                            @foreach ($owners as $owner)
                                <option value="{{ $owner->id }}" @if ((string) ($filters['owner_id'] ?? '') === (string) $owner->id) selected @endif>{{ $owner->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-auto">
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="form-control form-control-sm" aria-label="Desde">
                </div>
                <div class="col-auto">
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="form-control form-control-sm" aria-label="Hasta">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('activities.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Título</th>
                <th>Tipo</th>
                <th>Sujeto</th>
                <th>Estado</th>
                <th>Programada</th>
                <th>Prioridad</th>
                <th>Responsable</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($activities as $activity)
                @php
                    $subjectRoute = null;
                    $subjectLabel = '—';
                    if ($activity->subject) {
                        $morph = $activity->subject->getMorphClass();
                        $subjectRoute = match ($morph) {
                            'lead' => route('leads.show', $activity->subject),
                            'customer' => route('customers.show', $activity->subject),
                            'opportunity' => route('opportunities.show', $activity->subject),
                            default => null,
                        };
                        $subjectLabel = match (true) {
                            $activity->subject instanceof \App\Models\Lead => $activity->subject->code,
                            $activity->subject instanceof \App\Models\Customer => $activity->subject->code,
                            $activity->subject instanceof \App\Models\Opportunity => $activity->subject->code,
                            default => '#'.$activity->subject->getKey(),
                        };
                    }
                @endphp
                <tr data-testid="activity-row">
                    <td><a href="{{ route('activities.show', $activity) }}" class="fw-medium">{{ $activity->title }}</a></td>
                    <td>{{ $activity->type?->name ?? '—' }}</td>
                    <td>
                        @if ($subjectRoute)
                            <a href="{{ $subjectRoute }}">{{ $subjectLabel }}</a>
                        @else
                            {{ $subjectLabel }}
                        @endif
                    </td>
                    <td><x-badge-status :status="$activity->status"/></td>
                    <td class="text-nowrap">{{ $activity->scheduled_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>{{ $activity->priority ? ucfirst($activity->priority) : '—' }}</td>
                    <td>{{ $activity->owner?->name ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('activities.show', $activity) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $activity)
                            <a href="{{ route('activities.edit', $activity) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay actividades registradas.',
                            'hint' => 'Ajuste los filtros o registre una nueva actividad.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $activities])
        @endslot
    </x-table>
@endsection
