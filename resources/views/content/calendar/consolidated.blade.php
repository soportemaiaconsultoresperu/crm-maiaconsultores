@extends('layouts.app')

@section('title', 'Calendario consolidado de fechas importantes')
@section('page-title', 'Calendario consolidado')

@php
    $monthKey = $month->format('Y-m');
    $todayPeru = \App\Support\Content\PeruTime::date(\Carbon\CarbonImmutable::now());
    $navQuery = $selected !== [] ? ['projects' => $selected] : [];
@endphp

@section('content')
    <div class="alert alert-info" role="alert" data-testid="content-consolidated-notice">
        Vista consolidada para administradores. El filtro de proyectos sólo acota lo que ya puede ver:
        nunca muestra un proyecto al que no tenga acceso.
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title mb-0">Filtros</h3>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('content.calendar.consolidated') }}"
                  class="d-flex flex-wrap align-items-start gap-3" data-testid="content-consolidated-filters">
                <div>
                    <label for="month" class="form-label mb-0 small">Mes y año</label>
                    <input type="month" id="month" name="month" value="{{ $monthKey }}"
                           class="form-control form-control-sm" data-testid="content-consolidated-month">
                </div>

                <div>
                    <label for="projects" class="form-label mb-0 small">Proyectos</label>
                    <select id="projects" name="projects[]" multiple size="5"
                            class="form-select form-select-sm" data-testid="content-consolidated-projects">
                        @foreach ($projects as $option)
                            <option value="{{ $option->id }}" @selected(in_array($option->id, $selected, true))>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Uno, varios o ninguno (todos los proyectos).</div>
                </div>

                <div class="d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('content.calendar.consolidated', ['month' => $monthKey]) }}"
                       class="btn btn-sm btn-outline-secondary">Todos</a>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-testid="content-consolidated-nav">
        <a href="{{ route('content.calendar.consolidated', array_merge($navQuery, ['month' => $month->subMonth()->format('Y-m')])) }}"
           class="btn btn-outline-secondary btn-sm" data-testid="content-consolidated-prev">
            <i class="bi bi-chevron-left me-1" aria-hidden="true"></i> Mes anterior
        </a>
        <a href="{{ route('content.calendar.consolidated', array_merge($navQuery, ['month' => $month->addMonth()->format('Y-m')])) }}"
           class="btn btn-outline-secondary btn-sm" data-testid="content-consolidated-next">
            Mes siguiente <i class="bi bi-chevron-right ms-1" aria-hidden="true"></i>
        </a>
        <span class="badge text-bg-secondary" data-testid="content-consolidated-total">{{ $total }} fechas importantes</span>
        <span class="badge text-bg-secondary" data-testid="content-consolidated-content-total">{{ $contentTotal }} {{ $contentTotal === 1 ? 'contenido' : 'contenidos' }}</span>
    </div>

    <h2 class="h5 mb-3" data-testid="content-consolidated-period">{{ $month->translatedFormat('F Y') }}</h2>

    @if ($legend->isNotEmpty())
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-testid="content-consolidated-legend">
            <span class="small text-secondary">Proyectos:</span>
            @foreach ($legend as $legendProject)
                <span class="badge" @style([\App\Support\Content\ProjectColor::solidStyle($legendProject->color)])
                      data-testid="content-consolidated-legend-{{ $legendProject->id }}">{{ $legendProject->name }}</span>
            @endforeach
        </div>
    @endif

    <div class="card mb-3" data-testid="content-consolidated">
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
                                    data-testid="content-consolidated-cell-{{ $dayKey }}">
                                    <div class="small fw-medium mb-1">{{ $day->day }}</div>

                                    @foreach ($dayAnnotations as $annotation)
                                        @php
                                            $projectColor = $annotation->project?->color;
                                        @endphp
                                        <div class="small rounded p-1 mb-1 content-calendar-entry"
                                             @style([\App\Support\Content\ProjectColor::softStyle($projectColor)])
                                             data-testid="content-consolidated-annotation-{{ $annotation->id }}">
                                            <span class="badge" @style([\App\Support\Content\ProjectColor::solidStyle($projectColor)]) data-testid="content-consolidated-project-{{ $annotation->content_project_id }}">{{ $annotation->project?->name }}</span>
                                            <div class="fw-medium">{{ $annotation->name }}</div>
                                            @if ($annotation->notes)
                                                <div class="opacity-75">{{ $annotation->notes }}</div>
                                            @endif
                                        </div>
                                    @endforeach

                                    @foreach ($dayContents as $content)
                                        <a href="{{ route('content.projects.contents.show', [$content->content_project_id, $content]) }}"
                                           class="d-block small text-truncate text-decoration-none rounded px-1 mb-1 content-calendar-entry"
                                           @style([\App\Support\Content\ProjectColor::softStyle($content->project?->color)])
                                           title="{{ $content->project?->name }} — {{ $content->title }}"
                                           data-testid="content-consolidated-content-{{ $content->id }}">
                                            <span class="opacity-75">{{ \App\Support\Content\PeruTime::toPeru($content->planned_at)->format('H:i') }}</span>
                                            {{ $content->title }}
                                        </a>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($annotations === [] && $contents === [])
        <div class="alert alert-light border" data-testid="content-consolidated-empty">
            No hay fechas importantes ni contenidos planificados para la selección y el mes elegidos.
        </div>
    @endif
@endsection
