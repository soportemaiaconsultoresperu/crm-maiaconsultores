@extends('layouts.app')

@section('title', 'Fechas importantes de '.$project->name)
@section('page-title', 'Fechas importantes de '.$project->name)

@php
    // Presentation only: managing annotations is a mutation, so the controls
    // mirror the same `update` ability the endpoints enforce (members
    // included, refused on an archived project).
    $canManage = $canManage ?? Gate::allows('update', $project);
    $monthKey = $month->format('Y-m');
    $todayPeru = \App\Support\Content\PeruTime::date(\Carbon\CarbonImmutable::now());
    $emptyMonth = $annotations === [] && $contents === [];
    // Planned contents are painted with the project colour (S1.3).
    $contentStyle = \App\Support\Content\ProjectColor::softStyle($project->color);
@endphp

@section('content')
    @include('content.partials._project_band', ['project' => $project])
    @include('content.projects._read_only_notice', ['project' => $project])

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.show', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al proyecto
        </a>
        @if ($canViewConsolidated ?? false)
            <a href="{{ route('content.calendar.consolidated', ['month' => $monthKey]) }}" class="btn btn-outline-primary" data-testid="btn-consolidated-calendar">
                <i class="bi bi-calendar-range me-1" aria-hidden="true"></i> Calendario consolidado
            </a>
        @endif
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-testid="content-calendar-nav">
        <a href="{{ route('content.projects.calendar', ['contentProject' => $project, 'month' => $month->subMonth()->format('Y-m')]) }}"
           class="btn btn-outline-secondary btn-sm" data-testid="content-calendar-prev">
            <i class="bi bi-chevron-left me-1" aria-hidden="true"></i> Mes anterior
        </a>

        <form method="GET" action="{{ route('content.projects.calendar', $project) }}" class="d-flex align-items-center gap-2">
            <label for="month" class="form-label mb-0 small">Mes y año</label>
            <input type="month" id="month" name="month" value="{{ $monthKey }}" class="form-control form-control-sm" data-testid="content-calendar-month">
            <button type="submit" class="btn btn-sm btn-outline-primary">Ver</button>
        </form>

        <a href="{{ route('content.projects.calendar', ['contentProject' => $project, 'month' => $month->addMonth()->format('Y-m')]) }}"
           class="btn btn-outline-secondary btn-sm" data-testid="content-calendar-next">
            Mes siguiente <i class="bi bi-chevron-right ms-1" aria-hidden="true"></i>
        </a>

        <a href="{{ route('content.projects.calendar', ['contentProject' => $project, 'month' => \App\Support\Content\PeruTime::date(\Carbon\CarbonImmutable::now())]) }}"
           class="btn btn-link btn-sm">Mes actual</a>
    </div>

    <h2 class="h5 mb-3" data-testid="content-calendar-period">{{ $month->translatedFormat('F Y') }}</h2>

    <div class="card mb-3" data-testid="content-calendar">
        <div class="card-body p-0">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $weekday)
                            <th class="text-center small">{{ $weekday }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid['weeks'] as $week)
                        <tr>
                            @foreach ($week as $day)
                                @php
                                    $dayKey = $day->toDateString();
                                    $isCurrentMonth = $day->year === $month->year && $day->month === $month->month;
                                    $dayAnnotations = $annotations[$dayKey] ?? collect();
                                    $dayContents = $contents[$dayKey] ?? collect();
                                @endphp
                                <td class="align-top {{ $isCurrentMonth ? '' : 'bg-light text-secondary' }} {{ $dayKey === $todayPeru ? 'table-warning' : '' }}"
                                    style="width: 14.28%; min-width: 9rem;"
                                    data-date="{{ $dayKey }}"
                                    data-testid="content-calendar-cell-{{ $dayKey }}">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small fw-medium">{{ $day->day }}</span>
                                        @if ($canManage)
                                            <a href="{{ route('content.projects.calendar', ['contentProject' => $project, 'month' => $monthKey, 'date' => $dayKey]) }}#calendario-formulario"
                                               class="btn btn-sm btn-link p-0 text-secondary" title="Agregar fecha importante"
                                               data-testid="content-calendar-add-{{ $dayKey }}">
                                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                            </a>
                                        @endif
                                    </div>

                                    @foreach ($dayAnnotations as $annotation)
                                        <div class="small border rounded p-1 mb-1" data-testid="content-calendar-annotation-{{ $annotation->id }}">
                                            <span class="fw-medium">{{ $annotation->name }}</span>
                                            @if ($annotation->notes)
                                                <div class="text-secondary">{{ $annotation->notes }}</div>
                                            @endif
                                            @if ($canManage)
                                                <div class="d-flex flex-wrap gap-2">
                                                    <a href="{{ route('content.projects.calendar', ['contentProject' => $project, 'month' => $monthKey, 'edit' => $annotation->id]) }}#calendario-formulario"
                                                       class="small" data-testid="content-calendar-edit-{{ $annotation->id }}">Editar</a>
                                                    <x-swal-confirm
                                                        :action="route('content.projects.calendar.annotations.destroy', [$project, $annotation])"
                                                        method="DELETE"
                                                        title="¿Eliminar fecha importante?"
                                                        text="La anotación dejará de aparecer en el calendario del proyecto."
                                                        type="warning"
                                                        confirm-text="Sí, eliminar"
                                                        button-class="btn-sm btn-link p-0 text-danger"
                                                        data-testid="content-calendar-remove-{{ $annotation->id }}">
                                                        Eliminar
                                                    </x-swal-confirm>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach

                                    @foreach ($dayContents as $content)
                                        <div class="small text-truncate content-calendar-entry" @style([$contentStyle])
                                             data-testid="content-calendar-content-{{ $content->id }}" title="{{ $content->title }}">
                                            @if ($content->planned_at)
                                                <span class="opacity-75">{{ \App\Support\Content\PeruTime::toPeru($content->planned_at)->format('H:i') }}</span>
                                            @endif
                                            {{ $content->title }}
                                        </div>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($emptyMonth)
        <div class="alert alert-light border" data-testid="content-calendar-empty">
            No hay fechas importantes registradas en este mes. Haga clic en el símbolo + de un día para agregar una festividad, aniversario o actividad.
        </div>
    @endif

    @include('content.calendar._form', [
        'project' => $project,
        'month' => $month,
        'editing' => $editing,
        'formDate' => $formDate,
        'canManage' => $canManage,
    ])
@endsection
