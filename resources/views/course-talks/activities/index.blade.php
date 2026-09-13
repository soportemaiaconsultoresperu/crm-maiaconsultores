@extends('layouts.app')

@section('title', 'Cursos y charlas')
@section('page-title', 'Cursos y charlas')

@section('content')
    {{-- The spec's "Filter activities by type" MUST, expressed as a query-string
         contract so a filtered view is linkable and survives a reload. The options
         come from `CourseActivityType` itself (see the controller), so the
         vocabulary (`course`, `talk`, "all") and the Spanish labels are the enum's
         and cannot drift from it. No filter is the default and the list is
         unchanged in that case. --}}
    <form method="GET" action="{{ route('course-talks.activities.index') }}" class="card card-body mb-3" data-testid="course-talks-activities-type-filter">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-xl-3">
                <label class="form-label small" for="activities-type">Tipo de actividad</label>
                <select class="form-select form-select-sm" id="activities-type" name="activity_type">
                    <option value="" @selected($activeType === null)>Todas las actividades</option>
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($activeType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-sm-6 col-xl-3 d-flex flex-wrap align-items-center gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                <a href="{{ route('course-talks.activities.index') }}" class="btn btn-sm btn-outline-secondary">Ver todas</a>
                {{-- The active filter is stated in text, not by colour alone. --}}
                @if ($activeType !== null)
                    <span class="badge text-bg-info" data-testid="course-talks-activities-type-filter-active">Filtro activo: {{ $typeOptions[$activeType] ?? $activeType }}</span>
                @endif
            </div>
        </div>
    </form>

    @if ($typeFilterWarning !== null)
        <x-alert type="warning" data-testid="course-talks-activities-type-filter-invalid">
            @if ($typeFilterWarning === 'unknown')
                <p class="mb-1">El tipo de actividad <code>{{ $activeType }}</code> no es un valor válido y no coincide con ninguna actividad registrada.</p>
                <p class="mb-0">Elija <strong>Todas las actividades</strong> o uno de los tipos disponibles para volver a ver la lista.</p>
            @else
                <p class="mb-0">El filtro de tipo de actividad llegó con un valor que no es válido y se descartó: se muestra la lista completa. Use el selector para filtrar por tipo.</p>
            @endif
        </x-alert>
    @endif

    <x-table title="Actividades" data-testid="course-talks-activities-table">
        @slot('filters')
            @can('create', App\Models\Courses\CourseActivity::class)
                <a class="btn btn-sm btn-primary" data-testid="course-talks-activity-create-link" href="{{ route('course-talks.activities.create') }}">Nueva actividad</a>
            @endcan
            {{-- Contextual access point to the certificate template surface, gated
                 by exactly the ability its routes ask for
                 (CourseCertificateTemplatePolicy::manage), so the link is only
                 rendered for a user who can open it: no rendered control can
                 answer 403. --}}
            @can('manage', App\Models\Courses\CourseCertificateTemplate::class)
                <a class="btn btn-sm btn-outline-primary" data-testid="course-talks-template-list-link" href="{{ route('course-talks.templates.index') }}">Plantillas de certificados</a>
            @endcan
            {{-- Contextual access point to the delivery alert screen (Slice 7 unit
                 7.b). The screen's list is gated by exactly the ability this page
                 already requires (CourseActivityPolicy::viewAny), so the link is
                 only rendered for a user who can open it: no rendered control can
                 answer 403. --}}
            @can('viewAny', App\Models\Courses\CourseActivity::class)
                <a class="btn btn-sm btn-outline-danger" data-testid="course-talks-alerts-link" href="{{ route('course-talks.alerts.index') }}">Alertas de entrega</a>
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
                    {{-- The activity is the reusable definition; every operational
                         screen hangs off an EDITION. So the row offers a way into the
                         featured edition when there is one, and the reason it was
                         chosen is stated in words — never by colour or position alone.
                         An activity with no editions keeps "Ver detalle" as its only
                         affordance. The selection itself is the controller's (see
                         featuredEdition()); this view only renders it. --}}
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.activities.show', $activity) }}">Ver detalle</a>
                        @php $featured = $featuredEditions[$activity->id] ?? null; @endphp
                        @if ($featured !== null)
                            <div class="mt-2 d-flex flex-column align-items-end gap-1" data-testid="course-talks-activity-featured-edition-{{ $activity->id }}">
                                <span class="badge text-bg-info" data-testid="course-talks-activity-featured-reason-{{ $activity->id }}">{{ $featured['reason'] }}</span>
                                <span class="small text-secondary">
                                    {{ $featured['edition']->code ?: '—' }}@if ($featured['edition']->starts_on)<span class="ms-1">{{ $featured['edition']->starts_on->format('d/m/Y') }}</span>@endif
                                </span>
                                {{-- One gate per link, and they are the edition page's
                                     OWN gates, not new ones: the edition page's route
                                     requires `view`, so its attendance, participants,
                                     documents and comprobantes controls never render
                                     for a user who cannot open the edition — that is
                                     the outer `view` here. Teachers and sessions stay
                                     behind `update`, enrolling behind `create` and the
                                     grade matrix behind `manageGrades`, exactly as on
                                     the edition page. No rendered control can therefore
                                     answer 403. --}}
                                @can('view', $featured['edition'])
                                    <div class="d-flex flex-wrap justify-content-end gap-1" role="group" aria-label="Secciones de la edición destacada de {{ $activity->name }}">
                                        @can('update', App\Models\Courses\CourseEdition::class)
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.editions.teachers', $featured['edition']) }}">Docentes</a>
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.editions.sessions', $featured['edition']) }}">Sesiones</a>
                                        @endcan
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.enrollments.index', $featured['edition']) }}">Participantes</a>
                                        @can('create', App\Models\Courses\CourseEnrollment::class)
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.enrollments.create', $featured['edition']) }}">Inscribir participante</a>
                                        @endcan
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.attendance.index', $featured['edition']) }}">Asistencia</a>
                                        @can('manageGrades', $featured['edition'])
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.grades.index', $featured['edition']) }}">Notas</a>
                                        @endcan
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.documents.index', $featured['edition']) }}">Documentos</a>
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.commercial-documents.index', $featured['edition']) }}">Comprobantes</a>
                                    </div>
                                @endcan
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No hay actividades registradas.</td></tr>
            @endforelse
        @endslot
    </x-table>
@endsection
