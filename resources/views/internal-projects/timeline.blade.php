@extends('layouts.app')

@section('title', 'Línea de tiempo · '.$project->name)
@section('page-title', 'Línea de tiempo del proyecto')

@section('content')
    @php
        $color = $project->displayColor();
        $range = $timeline['range'];
        $today = $timeline['today'];
        $showUrl = route('internal-projects.show', $project);
        $stateLabels = [
            'done' => 'Terminada',
            'overdue' => 'Atrasada',
            'blocked' => 'Bloqueada',
            'in-progress' => 'En curso',
            'pending' => 'Pendiente',
        ];
    @endphp

    <style>
        .ip-gantt { --ip-day: 18px; --ip-left: 300px; --ip-color: #6c757d; --ip-row: 44px; }
        .ip-gantt-scroll { overflow-x: auto; border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem; background: #fff; }
        .ip-gantt-canvas { position: relative; width: calc(var(--ip-left) + var(--ip-days) * var(--ip-day)); min-width: 100%; }
        .ip-gantt-row { display: flex; min-height: var(--ip-row); border-bottom: 1px solid #eef0f2; }
        .ip-gantt-row:last-child { border-bottom: 0; }
        .ip-gantt-label { position: sticky; left: 0; z-index: 3; flex: 0 0 var(--ip-left); width: var(--ip-left); padding: .35rem .75rem; background: #fff; border-right: 1px solid var(--bs-border-color, #dee2e6); display: flex; flex-direction: column; justify-content: center; min-width: 0; }
        .ip-gantt-label a { color: inherit; text-decoration: none; }
        .ip-gantt-label a:hover, .ip-gantt-label a:focus { text-decoration: underline; }
        .ip-gantt-label .ip-gantt-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ip-gantt-label .ip-gantt-meta { font-size: .75rem; color: #6c757d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ip-gantt-row--phase { background: #f8f9fa; }
        .ip-gantt-row--phase .ip-gantt-label { background: #f8f9fa; font-weight: 600; }
        .ip-gantt-row--task .ip-gantt-label { padding-left: 1.5rem; }
        .ip-gantt-track { position: relative; flex: 0 0 calc(var(--ip-days) * var(--ip-day)); width: calc(var(--ip-days) * var(--ip-day));
            background-image: repeating-linear-gradient(to right, #e9ecef 0, #e9ecef 1px, transparent 1px, transparent calc(7 * var(--ip-day))); }
        .ip-gantt-head .ip-gantt-row { min-height: 28px; background: #fff; }
        .ip-gantt-head .ip-gantt-label { font-size: .8rem; color: #6c757d; }
        .ip-gantt-head { position: relative; z-index: 4; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
        .ip-gantt-month, .ip-gantt-week { position: absolute; top: 0; bottom: 0; display: flex; align-items: center; padding-left: .35rem; font-size: .75rem; white-space: nowrap; overflow: hidden; border-left: 1px solid #dee2e6; }
        .ip-gantt-month { font-weight: 600; text-transform: capitalize; }
        .ip-gantt-week { color: #6c757d; }
        .ip-gantt-bar { position: absolute; top: 50%; height: 20px; transform: translateY(-50%); min-width: 6px; border-radius: 4px; display: block; overflow: hidden; font-size: .7rem; line-height: 20px; padding: 0 .3rem; white-space: nowrap; text-overflow: ellipsis; text-decoration: none; color: #fff; }
        .ip-gantt-bar:focus-visible, .ip-gantt-milestone:focus-visible { outline: 2px solid #0d6efd; outline-offset: 2px; }
        .ip-gantt-bar--done, .ip-gantt-swatch--done { background: #198754; color: #fff; }
        .ip-gantt-bar--overdue, .ip-gantt-swatch--overdue { background: #dc3545; color: #fff; }
        .ip-gantt-bar--blocked, .ip-gantt-swatch--blocked { background: #ffc107; color: #212529; }
        .ip-gantt-bar--in-progress, .ip-gantt-swatch--in-progress { background: var(--ip-color); color: var(--ip-color-text, #fff); }
        .ip-gantt-bar--pending, .ip-gantt-swatch--pending { background: #f1f3f5; color: #495057; border: 2px solid #adb5bd; line-height: 16px; }
        .ip-gantt-summary { position: absolute; top: 50%; height: 12px; transform: translateY(-50%); min-width: 6px; border-radius: 3px; background: #ced4da; overflow: hidden; }
        .ip-gantt-summary-fill { height: 100%; background: #343a40; }
        .ip-gantt-percent { position: absolute; top: 50%; transform: translateY(-50%); margin-left: .35rem; font-size: .7rem; font-weight: 600; color: #343a40; white-space: nowrap; }
        .ip-gantt-milestone { position: absolute; top: 50%; width: 16px; height: 16px; transform: translate(-50%, -50%) rotate(45deg); border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0, 0, 0, .25); display: block; }
        .ip-gantt-milestone.ip-gantt-bar--pending { border-width: 2px; border-color: #6c757d; }
        .ip-gantt-today-layer { position: absolute; top: 0; bottom: 0; left: var(--ip-left); width: calc(var(--ip-days) * var(--ip-day)); pointer-events: none; z-index: 2; }
        .ip-gantt-today { position: absolute; top: 0; bottom: 0; width: 0; border-left: 2px solid #dc3545; }
        .ip-gantt-today span { position: absolute; top: 2px; left: 3px; font-size: .65rem; font-weight: 700; color: #fff; background: #dc3545; border-radius: 3px; padding: 0 .25rem; }
        .ip-gantt-legend { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; font-size: .85rem; }
        .ip-gantt-legend-item { display: inline-flex; align-items: center; gap: .4rem; }
        .ip-gantt-swatch { display: inline-block; width: 22px; height: 12px; border-radius: 3px; }
        .ip-gantt-swatch--pending { line-height: normal; border-width: 2px; }
        .ip-gantt-swatch--milestone { width: 11px; height: 11px; transform: rotate(45deg); background: var(--ip-color); border-radius: 1px; }
        .ip-gantt-swatch--summary { background: linear-gradient(to right, #343a40 50%, #ced4da 50%); height: 8px; }
        .ip-gantt-swatch--today { width: 0; height: 16px; border-left: 2px solid #dc3545; border-radius: 0; }
        .ip-gantt-dot { display: inline-block; width: .8rem; height: .8rem; border-radius: 50%; background: var(--ip-color); vertical-align: middle; }
        @media (max-width: 767.98px) {
            .ip-gantt { --ip-left: 170px; --ip-day: 14px; }
            .ip-gantt-row--task .ip-gantt-label { padding-left: .75rem; }
        }
        @media print { .ip-gantt-scroll { overflow: visible; } }
    </style>

    <div class="ip-gantt" style="--ip-days: {{ $range['days'] }}; --ip-color: {{ $color }}; --ip-color-text: {{ \App\Support\Content\ProjectColor::textColor($color) }};" data-testid="ip-gantt">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <a href="{{ $showUrl }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al proyecto
            </a>
            <a href="{{ route('internal-projects.index') }}" class="btn btn-link">Proyectos internos</a>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <h2 class="h5 mb-0">
                    <span class="ip-gantt-dot me-1" aria-hidden="true"></span>
                    {{ $project->name }}
                    <small class="text-muted fw-normal">{{ $project->code }}</small>
                </h2>
                <span class="badge {{ $project->status->badgeClass() }}">{{ $project->status->label() }}</span>
                <span class="text-muted small">
                    Avance: <strong>{{ $progress['percent'] }} %</strong>
                    ({{ $progress['terminadas'] }} de {{ $progress['total'] }} tareas terminadas{{ $progress['atrasadas'] > 0 ? ', '.$progress['atrasadas'].' atrasadas' : '' }})
                </span>
                @if ($project->responsible)
                    <span class="text-muted small">Responsable: {{ $project->responsible->name }}</span>
                @endif
                <span class="text-muted small ms-md-auto">
                    Rango: {{ \Carbon\CarbonImmutable::parse($range['start'])->format('d/m/Y') }} – {{ \Carbon\CarbonImmutable::parse($range['end'])->format('d/m/Y') }}
                </span>
            </div>
        </div>

        @if ($range['truncated'])
            <div class="alert alert-warning py-2" role="status">
                El proyecto abarca más de {{ \App\Services\InternalProjects\InternalProjectTimelineBuilder::MAX_MONTHS }} meses; se muestran solo los primeros {{ \App\Services\InternalProjects\InternalProjectTimelineBuilder::MAX_MONTHS }} meses.
            </div>
        @endif

        @if (! $today['in_range'])
            <p class="text-muted small mb-2">Hoy ({{ $today['label'] }}) está fuera del rango del proyecto.</p>
        @endif

        <div class="ip-gantt-legend mb-2" data-testid="ip-gantt-legend" aria-label="Leyenda">
            @foreach ($stateLabels as $state => $label)
                <span class="ip-gantt-legend-item"><span class="ip-gantt-swatch ip-gantt-swatch--{{ $state }}" aria-hidden="true"></span> {{ $label }}</span>
            @endforeach
            <span class="ip-gantt-legend-item"><span class="ip-gantt-swatch ip-gantt-swatch--milestone" aria-hidden="true"></span> Hito</span>
            <span class="ip-gantt-legend-item"><span class="ip-gantt-swatch ip-gantt-swatch--summary" aria-hidden="true"></span> Fase (avance)</span>
            <span class="ip-gantt-legend-item"><span class="ip-gantt-swatch ip-gantt-swatch--today" aria-hidden="true"></span> Hoy</span>
        </div>

        <div class="ip-gantt-scroll" tabindex="0" aria-label="Diagrama de Gantt; desplácese horizontalmente para ver todo el rango">
            <div class="ip-gantt-canvas">
                <div class="ip-gantt-head">
                    <div class="ip-gantt-row">
                        <div class="ip-gantt-label">Fase / tarea</div>
                        <div class="ip-gantt-track" style="background-image: none;">
                            @foreach ($timeline['months'] as $month)
                                <div class="ip-gantt-month" style="left: {{ $month['left_pct'] }}%; width: {{ $month['width_pct'] }}%;">{{ $month['label'] }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="ip-gantt-row">
                        <div class="ip-gantt-label">Responsable · estado</div>
                        <div class="ip-gantt-track" style="background-image: none;">
                            @foreach ($timeline['weeks'] as $week)
                                <div class="ip-gantt-week" style="left: {{ $week['left_pct'] }}%;" title="Semana del {{ \Carbon\CarbonImmutable::parse($week['date'])->format('d/m/Y') }}">{{ $week['label'] }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="ip-gantt-body" style="position: relative;">
                    @forelse ($timeline['rows'] as $row)
                        @if ($row['type'] === 'phase')
                            @php
                                $phaseTip = $row['name'].' · '.$row['percent'].' % de avance'
                                    .($row['has_bar'] ? ' · '.$row['start_label'].' – '.$row['end_label'] : '')
                                    .' · '.$row['task_count'].' tareas';
                            @endphp
                            <div class="ip-gantt-row ip-gantt-row--phase" data-testid="ip-gantt-phase-{{ $row['id'] }}">
                                <div class="ip-gantt-label" title="{{ $phaseTip }}">
                                    <span class="ip-gantt-title">{{ $row['name'] }}</span>
                                    <span class="ip-gantt-meta">
                                        {{ $row['percent'] }} % · {{ $row['task_count'] }} {{ $row['task_count'] === 1 ? 'tarea' : 'tareas' }}
                                        @if ($row['overdue_count'] > 0)
                                            · <span class="text-danger">{{ $row['overdue_count'] }} atrasadas</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="ip-gantt-track">
                                    @if ($row['has_bar'])
                                        <div class="ip-gantt-summary" style="left: {{ $row['left_pct'] }}%; width: {{ $row['width_pct'] }}%;" title="{{ $phaseTip }}"
                                             role="progressbar" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de {{ $row['name'] }}">
                                            <div class="ip-gantt-summary-fill" style="width: {{ $row['percent'] }}%;"></div>
                                        </div>
                                        <span class="ip-gantt-percent" style="left: {{ $row['left_pct'] + $row['width_pct'] }}%;">{{ $row['percent'] }} %</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            @php
                                $taskUrl = $showUrl.'#task-'.$row['id'];
                                $taskTip = $row['title'].' · '.$row['start_label'].' – '.$row['end_label']
                                    .' · '.$row['status_label'].($row['overdue'] ? ' (atrasada)' : '')
                                    .($row['milestone'] ? ' · Hito' : '')
                                    .($row['responsible'] ? ' · '.$row['responsible'] : '');
                            @endphp
                            <div class="ip-gantt-row ip-gantt-row--task" data-testid="ip-gantt-task-{{ $row['id'] }}">
                                <div class="ip-gantt-label">
                                    <a href="{{ $taskUrl }}" class="ip-gantt-title" title="{{ $taskTip }}">
                                        @if ($row['milestone'])
                                            <i class="bi bi-diamond-fill me-1 small" style="color: var(--ip-color);" aria-label="Hito"></i>
                                        @endif
                                        {{ $row['title'] }}
                                    </a>
                                    <span class="ip-gantt-meta">
                                        {{ $row['responsible'] ?? 'Sin responsable' }} ·
                                        <span class="badge {{ $row['badge_class'] }}">{{ $row['status_label'] }}</span>
                                        @if ($row['overdue'])
                                            <span class="badge text-bg-danger">Atrasada</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="ip-gantt-track">
                                    @if ($row['milestone'])
                                        @if ($row['marker_left_pct'] !== null)
                                            <a href="{{ $taskUrl }}" class="ip-gantt-milestone ip-gantt-bar--{{ $row['state'] }}" style="left: {{ $row['marker_left_pct'] }}%;"
                                               title="{{ $taskTip }}" aria-label="{{ $taskTip }}" data-testid="ip-gantt-milestone-{{ $row['id'] }}"></a>
                                        @endif
                                    @elseif ($row['has_bar'])
                                        <a href="{{ $taskUrl }}" class="ip-gantt-bar ip-gantt-bar--{{ $row['state'] }}" style="left: {{ $row['left_pct'] }}%; width: {{ $row['width_pct'] }}%;"
                                           title="{{ $taskTip }}" aria-label="{{ $taskTip }}" data-testid="ip-gantt-bar-{{ $row['id'] }}">{{ $row['title'] }}</a>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="ip-gantt-row">
                            <div class="ip-gantt-label text-muted">Sin fases ni tareas</div>
                            <div class="ip-gantt-track d-flex align-items-center ps-3 text-muted small">
                                Este proyecto aún no tiene fases ni tareas planificadas.
                            </div>
                        </div>
                    @endforelse

                    @if ($today['in_range'])
                        <div class="ip-gantt-today-layer" aria-hidden="true">
                            <div class="ip-gantt-today" style="left: {{ $today['left_pct'] }}%;" title="Hoy, {{ $today['label'] }}" data-testid="ip-gantt-today"><span>Hoy</span></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <p class="text-muted small mt-2 mb-0">Las fechas se muestran en hora de Lima. Haga clic en una barra para ver la tarea en el proyecto.</p>
    </div>
@endsection
