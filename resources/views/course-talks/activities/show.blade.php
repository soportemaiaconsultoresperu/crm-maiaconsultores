@extends('layouts.app')

@section('title', $activity->name)
@section('page-title', $activity->name)

@section('content')
    <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary mb-3">Volver al catálogo</a>
    <div class="card mb-3" data-testid="course-talks-activity-detail">
        <div class="card-header"><h3 class="card-title mb-0">{{ $activity->type->label() }}</h3></div>
        <div class="card-body row g-3">
            <div class="col-md-3"><span class="small text-secondary d-block">Código</span><code>{{ $activity->code }}</code></div>
            <div class="col-md-3"><span class="small text-secondary d-block">Horas académicas</span>{{ $activity->official_academic_hours }}</div>
            <div class="col-md-6"><span class="small text-secondary d-block">Temario base</span>{{ implode(', ', array_filter((array) ($activity->base_syllabus_json ?? []), 'is_scalar')) ?: '—' }}</div>
        </div>
    </div>
    <x-table title="Dictados" data-testid="course-talks-activity-editions-table">
        @slot('filters')
            @can('create', App\Models\Courses\CourseEdition::class)
                <a class="btn btn-sm btn-primary" data-testid="course-talks-edition-create-link" href="{{ route('course-talks.editions.create', $activity) }}">Nuevo dictado</a>
            @endcan
        @endslot
        @slot('headers')<tr><th>Código</th><th>Fechas</th><th>Modalidad</th><th>Estado</th><th></th></tr>@endslot
        @slot('rows')
            @forelse ($activity->editions as $edition)
                <tr><td><code>{{ $edition->code ?: '—' }}</code></td><td>{{ $edition->starts_on?->format('d/m/Y') }} — {{ $edition->ends_on?->format('d/m/Y') }}</td><td>{{ $edition->modality->label() }}</td><td><span class="badge text-bg-secondary">{{ $edition->state->label() }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.editions.show', $edition) }}">Ver detalle</a></td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-secondary py-4">No hay dictados registrados.</td></tr>
            @endforelse
        @endslot
    </x-table>
@endsection
