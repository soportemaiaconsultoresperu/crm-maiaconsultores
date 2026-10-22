@extends('layouts.app')

@section('title', $activity->name)
@section('page-title', $activity->name)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary">Volver al catálogo</a>
        @can('update', $activity)
            <a href="{{ route('course-talks.activities.edit', $activity) }}" class="btn btn-outline-primary" data-testid="course-talks-activity-edit-link">Editar</a>
        @endcan
        {{-- Deleting is offered only while the activity has no dictados, the same
             rule CourseActivityService::delete() enforces. --}}
        @can('delete', $activity)
            @if ($activity->editions->isEmpty())
                <form method="POST" action="{{ route('course-talks.activities.destroy', $activity) }}" class="d-inline"
                      onsubmit="return confirm('¿Eliminar este curso o charla? Esta acción no se puede deshacer desde la pantalla.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger" data-testid="course-talks-activity-delete">Eliminar</button>
                </form>
            @endif
        @endcan
    </div>

    @unless ($activity->is_active)
        <x-alert type="warning" data-testid="course-talks-activity-inactive">Este curso o charla está inactivo: no aparece en el catálogo por defecto y no admite nuevos dictados. Los dictados existentes no cambian.</x-alert>
    @endunless

    <div class="card mb-3" data-testid="course-talks-activity-detail">
        <div class="card-header"><h3 class="card-title mb-0">{{ $activity->type->label() }}@unless ($activity->is_active) <span class="badge text-bg-secondary ms-1">Inactiva</span>@endunless</h3></div>
        <div class="card-body row g-3">
            <div class="col-md-3"><span class="small text-secondary d-block">Código</span><code>{{ $activity->code }}</code></div>
            <div class="col-md-3"><span class="small text-secondary d-block">Horas académicas</span>{{ $activity->official_academic_hours }}</div>
            <div class="col-md-6"><span class="small text-secondary d-block">Temario base</span>{{ implode(', ', array_filter((array) ($activity->base_syllabus_json ?? []), 'is_scalar')) ?: '—' }}</div>
        </div>
    </div>
    <x-table title="Dictados" data-testid="course-talks-activity-editions-table">
        @slot('filters')
            @can('create', App\Models\Courses\CourseEdition::class)
                @if ($activity->is_active)
                <a class="btn btn-sm btn-primary" data-testid="course-talks-edition-create-link" href="{{ route('course-talks.editions.create', $activity) }}">Nuevo dictado</a>
                @endif
            @endcan
        @endslot
        @slot('headers')<tr><th>Código</th><th>Fechas</th><th>Modalidad</th><th>Estado</th><th class="text-end">Precio</th><th></th></tr>@endslot
        @slot('rows')
            @forelse ($activity->editions as $edition)
                <tr><td><code>{{ $edition->code ?: '—' }}</code></td><td>{{ $edition->starts_on?->format('d/m/Y') }} — {{ $edition->ends_on?->format('d/m/Y') }}</td><td>{{ $edition->modality->label() }}</td><td><span class="badge text-bg-secondary">{{ $edition->state->label() }}</span></td>{{-- The price each dictado charges, printed here so a mistyped amount is visible without opening every one. The exact stored string, never a reformatted approximation. --}}<td class="text-end" data-testid="course-talks-edition-price-{{ $edition->id }}">{{ $edition->price_amount }} {{ $edition->currency }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.editions.show', $edition) }}">Ver detalle</a></td></tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No hay dictados registrados.</td></tr>
            @endforelse
        @endslot
    </x-table>
@endsection
