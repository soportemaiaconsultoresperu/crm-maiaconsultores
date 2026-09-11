@extends('layouts.app')

@section('title', 'Cursos y charlas')
@section('page-title', 'Cursos y charlas')

@section('content')
    <x-table title="Actividades" data-testid="course-talks-activities-table">
        @slot('filters')
            @can('create', App\Models\Courses\CourseActivity::class)
                <a class="btn btn-sm btn-primary" data-testid="course-talks-activity-create-link" href="{{ route('course-talks.activities.create') }}">Nueva actividad</a>
            @endcan
        @endslot
        @slot('headers')
            <tr><th>Código</th><th>Tipo</th><th>Actividad</th><th>Horas</th><th>Ediciones</th><th></th></tr>
        @endslot
        @slot('rows')
            @forelse ($activities as $activity)
                <tr data-testid="course-talks-activity-{{ $activity->id }}">
                    <td><code>{{ $activity->code }}</code></td>
                    <td><span class="badge text-bg-secondary">{{ $activity->type->label() }}</span></td>
                    <td><strong>{{ $activity->name }}</strong></td>
                    <td>{{ $activity->official_academic_hours }}</td>
                    <td>{{ $activity->editions_count }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.activities.show', $activity) }}">Ver detalle</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No hay actividades registradas.</td></tr>
            @endforelse
        @endslot
    </x-table>
@endsection
