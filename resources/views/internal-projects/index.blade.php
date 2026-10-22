@extends('layouts.app')

@section('title', 'Proyectos internos')
@section('page-title', 'Proyectos internos')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
        <form method="GET" action="{{ route('internal-projects.index') }}" class="d-flex gap-2 align-items-end me-auto" data-testid="internal-projects-filter">
            <div>
                <label for="status" class="form-label small mb-1">Estado</label>
                <select name="status" id="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    @foreach (\App\Enums\InternalProjects\ProjectStatus::labels() as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Filtrar</button></noscript>
        </form>
        <a href="{{ route('internal-projects.create') }}" class="btn btn-primary" data-testid="btn-new-internal-project">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo proyecto
        </a>
    </div>

    <div class="row g-3" data-testid="internal-projects-list">
        @forelse ($projects as $project)
            @php
                $summary = $summaries[$project->id];
                $progress = $summary['progress'];
                $color = $project->displayColor();
            @endphp
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 overflow-hidden" data-testid="project-card-{{ $project->id }}">
                    <div @style(['height: 6px', 'background-color: '.$color]) aria-hidden="true"></div>
                    <div class="card-body d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h3 class="h5 mb-0">{{ $project->name }}</h3>
                                <div class="small text-secondary">{{ $project->code }}</div>
                            </div>
                            <span class="badge {{ $project->status->badgeClass() }}">{{ $project->status->label() }}</span>
                        </div>

                        <div class="small text-secondary">
                            <i class="bi bi-person me-1" aria-hidden="true"></i>{{ $project->responsible?->name ?? 'Sin responsable' }}
                            <span class="ms-2"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i>{{ $project->starts_on?->format('d/m/Y') ?? '—' }} – {{ $project->ends_on?->format('d/m/Y') ?? '—' }}</span>
                        </div>

                        <div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Avance general</span>
                                <strong data-testid="project-percent-{{ $project->id }}">{{ $progress['percent'] }} %</strong>
                            </div>
                            @include('internal-projects.partials.progress-bar', ['percent' => $progress['percent'], 'color' => $color, 'label' => 'Avance de '.$project->name])
                        </div>

                        @if ($project->phases->isNotEmpty())
                            <div class="small">
                                @foreach ($project->phases as $phase)
                                    @php $phasePercent = $summary['phases'][$phase->id]['percent'] ?? 0; @endphp
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="text-truncate" style="width: 40%" title="{{ $phase->name }}">{{ $phase->name }}</span>
                                        <div class="flex-grow-1">
                                            @include('internal-projects.partials.progress-bar', ['percent' => $phasePercent, 'color' => $color, 'height' => 6, 'label' => 'Avance de la fase '.$phase->name])
                                        </div>
                                        <span class="text-secondary text-end" style="width: 3rem">{{ $phasePercent }} %</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @include('internal-projects.partials.counts', ['progress' => $progress])

                        <div class="small">
                            <div class="fw-semibold mb-1">Próximos hitos</div>
                            @forelse ($summary['milestones'] as $milestone)
                                <div class="d-flex justify-content-between gap-2 {{ $milestone->isOverdue() ? 'text-danger' : '' }}">
                                    <span class="text-truncate"><i class="bi bi-diamond-fill me-1" aria-hidden="true"></i>{{ $milestone->title }}</span>
                                    <span class="text-nowrap">{{ $milestone->due_on->format('d/m/Y') }}</span>
                                </div>
                            @empty
                                <div class="text-secondary">Sin hitos pendientes.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2 justify-content-end">
                        <a href="{{ route('internal-projects.timeline', $project) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i> Línea de tiempo
                        </a>
                        <a href="{{ route('internal-projects.show', $project) }}" class="btn btn-sm btn-primary">Ver</a>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                @include('layouts.partials.empty-state', ['message' => 'No hay proyectos internos.'])
            </div>
        @endforelse
    </div>

    <div class="mt-3">{{ $projects->links() }}</div>
@endsection
