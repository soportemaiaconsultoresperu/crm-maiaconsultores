@extends('layouts.app')

@section('title', $project->name)
@section('page-title', 'Proyecto interno')

@section('content')
    @php
        $color = $project->displayColor();
        $audienceDetail = match ($project->audience_type) {
            'roles' => $project->audienceRoles->pluck('name')->join(', '),
            'users' => $project->audienceUsers->pluck('name')->join(', '),
            default => null,
        };
        $filtered = array_filter($filters) !== [];
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('internal-projects.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Proyectos internos
        </a>
        <a href="{{ route('internal-projects.timeline', $project) }}" class="btn btn-outline-secondary" data-testid="btn-project-timeline">
            <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i> Línea de tiempo
        </a>
        @if ($canManage)
            <a href="{{ route('internal-projects.edit', $project) }}" class="btn btn-primary ms-auto" data-testid="btn-edit-project">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar proyecto
            </a>
        @endif
        @can('delete', $project)
            <form method="POST" action="{{ route('internal-projects.destroy', $project) }}" class="d-inline"
                  onsubmit="return confirm('¿Eliminar este proyecto interno con sus fases y tareas?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger" data-testid="btn-delete-project">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar
                </button>
            </form>
        @endcan
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" data-testid="internal-project-errors">
            <div class="fw-semibold mb-1">No se pudo guardar. Revise lo siguiente:</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card overflow-hidden mb-3" data-testid="internal-project-show">
        <div @style(['height: 8px', 'background-color: '.$color]) aria-hidden="true"></div>
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h2 class="h4 mb-0">{{ $project->name }}</h2>
                    <div class="text-secondary small">{{ $project->code }}</div>
                </div>
                <span class="badge {{ $project->status->badgeClass() }} fs-6">{{ $project->status->label() }}</span>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between small mb-1">
                    <span>Avance general</span>
                    <strong data-testid="project-percent">{{ $progress['percent'] }} %</strong>
                </div>
                @include('internal-projects.partials.progress-bar', ['percent' => $progress['percent'], 'color' => $color, 'height' => 14, 'label' => 'Avance del proyecto'])
            </div>
            @include('internal-projects.partials.counts', ['progress' => $progress])

            <dl class="row mb-0 mt-3 small">
                <dt class="col-sm-3">Responsable</dt>
                <dd class="col-sm-9">{{ $project->responsible?->name ?? '—' }}</dd>
                <dt class="col-sm-3">Fechas planificadas</dt>
                <dd class="col-sm-9">{{ $project->starts_on?->format('d/m/Y') ?? '—' }} – {{ $project->ends_on?->format('d/m/Y') ?? '—' }}</dd>
                <dt class="col-sm-3">Equipo</dt>
                <dd class="col-sm-9">{{ $project->members->pluck('name')->join(', ') ?: '—' }}</dd>
                <dt class="col-sm-3">Audiencia</dt>
                <dd class="col-sm-9">
                    {{ \App\Models\InternalProjects\InternalProject::AUDIENCE_LABELS[$project->audience_type] ?? $project->audience_type }}
                    @if ($audienceDetail)
                        <span class="text-secondary">— {{ $audienceDetail }}</span>
                    @endif
                </dd>
                @if ($project->description)
                    <dt class="col-sm-3">Descripción</dt>
                    <dd class="col-sm-9">{!! nl2br(e($project->description)) !!}</dd>
                @endif
            </dl>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <form method="GET" action="{{ route('internal-projects.show', $project) }}" class="d-flex flex-wrap gap-2 align-items-end me-auto" data-testid="task-filters">
            <div>
                <label for="filter-phase" class="form-label small mb-1">Fase</label>
                <select name="phase" id="filter-phase" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    @foreach ($project->phases as $phase)
                        <option value="{{ $phase->id }}" @selected($filters['phase'] === $phase->id)>{{ $phase->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-status" class="form-label small mb-1">Estado</label>
                <select name="status" id="filter-status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    @foreach (\App\Enums\InternalProjects\TaskStatus::labels() as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-responsible" class="form-label small mb-1">Responsable</label>
                <select name="responsible" id="filter-responsible" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    @foreach ($responsibles as $person)
                        <option value="{{ $person->id }}" @selected($filters['responsible'] === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Filtrar</button>
            @if ($filtered)
                <a href="{{ route('internal-projects.show', $project) }}" class="btn btn-sm btn-link">Quitar filtros</a>
            @endif
        </form>
        @if ($canWork)
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#phase-create-modal" data-testid="btn-add-phase">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar fase
            </button>
        @endif
    </div>

    @forelse ($project->phases as $phase)
        @php
            $phaseProgress = $phasesProgress[$phase->id];
            $phaseTasks = $tasksByPhase->get($phase->id, collect());
        @endphp
        @continue($filters['phase'] !== null && $filters['phase'] !== $phase->id)
        <div class="card mb-3" id="phase-{{ $phase->id }}" data-testid="phase-{{ $phase->id }}">
            <div class="card-header">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h3 class="card-title h6 mb-0 me-2">{{ $phase->name }}</h3>
                    @if ($phase->starts_on || $phase->ends_on)
                        <span class="small text-secondary">{{ $phase->starts_on?->format('d/m/Y') ?? '—' }} – {{ $phase->ends_on?->format('d/m/Y') ?? '—' }}</span>
                    @endif
                    <div class="flex-grow-1" style="min-width: 8rem; max-width: 20rem">
                        @include('internal-projects.partials.progress-bar', ['percent' => $phaseProgress['percent'], 'color' => $color, 'label' => 'Avance de la fase '.$phase->name])
                    </div>
                    <span class="small fw-semibold">{{ $phaseProgress['percent'] }} %</span>
                    <span class="small text-secondary">({{ $phaseProgress['terminadas'] }}/{{ $phaseProgress['total'] }} tareas)</span>
                    @if ($canWork)
                        <div class="ms-auto d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#task-create-modal-{{ $phase->id }}" data-testid="btn-add-task">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Tarea
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#phase-edit-modal-{{ $phase->id }}" aria-label="Editar fase {{ $phase->name }}">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <form method="POST" action="{{ route('internal-projects.phases.destroy', [$project, $phase]) }}" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar esta fase?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Eliminar fase {{ $phase->name }}">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tarea</th>
                            <th>Responsable</th>
                            <th class="text-nowrap">Inicio</th>
                            <th class="text-nowrap">Vencimiento</th>
                            <th>Estado</th>
                            <th class="text-end">Peso</th>
                            @if ($canWork)
                                <th class="text-end">Acción</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($phaseTasks as $task)
                            <tr id="task-{{ $task->id }}" data-testid="task-row">
                                <td>
                                    @if ($task->is_milestone)
                                        <i class="bi bi-diamond-fill text-warning me-1" title="Hito" aria-label="Hito"></i>
                                    @endif
                                    {{ $task->title }}
                                    @if ($task->isOverdue())
                                        <span class="badge text-bg-danger ms-1">Atrasada</span>
                                    @endif
                                    @if ($task->description)
                                        <div class="small text-secondary">{{ \Illuminate\Support\Str::limit($task->description, 120) }}</div>
                                    @endif
                                </td>
                                <td>{{ $task->responsible?->name ?? '—' }}</td>
                                <td class="text-nowrap">{{ $task->starts_on->format('d/m/Y') }}</td>
                                <td class="text-nowrap">{{ $task->due_on->format('d/m/Y') }}</td>
                                <td>
                                    @if ($canWork)
                                        <form method="POST" action="{{ route('internal-projects.tasks.status', [$project, $task]) }}" class="d-flex gap-1" data-testid="task-status-form">
                                            @csrf
                                            @method('PATCH')
                                            <select name="status" class="form-select form-select-sm" aria-label="Estado de {{ $task->title }}" onchange="this.form.submit()">
                                                @foreach (\App\Enums\InternalProjects\TaskStatus::labels() as $value => $label)
                                                    <option value="{{ $value }}" @selected($task->status->value === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Guardar</button></noscript>
                                        </form>
                                    @else
                                        <span class="badge {{ $task->status->badgeClass() }}">{{ $task->status->label() }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $task->weight }}</td>
                                @if ($canWork)
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#task-edit-modal-{{ $task->id }}" aria-label="Editar tarea {{ $task->title }}">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                        <form method="POST" action="{{ route('internal-projects.tasks.destroy', [$project, $task]) }}" class="d-inline"
                                              onsubmit="return confirm('¿Eliminar esta tarea?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Eliminar tarea {{ $task->title }}">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canWork ? 7 : 6 }}" class="text-secondary small">
                                    {{ $filtered ? 'No hay tareas que coincidan con los filtros.' : 'Esta fase aún no tiene tareas.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body">
                @include('layouts.partials.empty-state', ['message' => $canWork
                    ? 'El proyecto aún no tiene fases. Agregue la primera con «Agregar fase».'
                    : 'El proyecto aún no tiene fases.'])
            </div>
        </div>
    @endforelse

    @if ($canWork)
        <x-modal id="phase-create-modal" title="Agregar fase">
            @include('internal-projects.partials.phase-form', ['phase' => null])
        </x-modal>
        @foreach ($project->phases as $phase)
            <x-modal id="phase-edit-modal-{{ $phase->id }}" title="Editar fase">
                @include('internal-projects.partials.phase-form', ['phase' => $phase])
            </x-modal>
            <x-modal id="task-create-modal-{{ $phase->id }}" title="Agregar tarea a {{ $phase->name }}" size="lg">
                @include('internal-projects.partials.task-form', ['task' => null, 'phaseId' => $phase->id])
            </x-modal>
        @endforeach
        @foreach ($project->tasks as $task)
            <x-modal id="task-edit-modal-{{ $task->id }}" title="Editar tarea" size="lg">
                @include('internal-projects.partials.task-form', ['task' => $task, 'phaseId' => $task->internal_project_phase_id])
            </x-modal>
        @endforeach
    @endif
@endsection
