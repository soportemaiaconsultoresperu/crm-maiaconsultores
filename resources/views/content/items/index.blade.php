@extends('layouts.app')

@section('title', 'Cronograma de '.$project->name)
@section('page-title', 'Cronograma de '.$project->name)

@php
    // Presentation only: the create form is a mutation, so its link mirrors
    // the same `update` ability the endpoint enforces.
    $canManage = Gate::allows('update', $project);
    // The export links carry the filters currently applied to the grid, using
    // the same normalised filter set the controller reads.
    $exportFilters = array_filter($filters, fn ($value): bool => (string) $value !== '');
    $exportQuery = $exportFilters === [] ? '' : '?'.http_build_query($exportFilters);
    // Optional standard fields the project hides (S2): no column and no filter.
    $shows = fn (string $field): bool => ! $project->isStandardFieldHidden($field);
    $columnCount = 7 + count($project->visibleStandardFields());
    $statusClasses = [
        \App\Enums\Content\ContentItemStatus::Incomplete->value => 'text-bg-secondary',
        \App\Enums\Content\ContentItemStatus::InProgress->value => 'text-bg-info',
        \App\Enums\Content\ContentItemStatus::CompletePendingPublish->value => 'text-bg-warning',
        \App\Enums\Content\ContentItemStatus::Published->value => 'text-bg-success',
    ];
@endphp

@section('content')
    @includeIf('content.partials._project_band', ['project' => $project])
    @include('content.projects._read_only_notice', ['project' => $project])

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.show', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al proyecto
        </a>
        <a href="{{ route('content.projects.ideas.index', $project) }}" class="btn btn-outline-secondary" data-testid="btn-content-idea-section">
            <i class="bi bi-lightbulb me-1" aria-hidden="true"></i> Ver ideas
        </a>
        <a href="{{ route('content.projects.contents.trash', $project) }}" class="btn btn-outline-secondary" data-testid="btn-content-trash">
            <i class="bi bi-trash me-1" aria-hidden="true"></i> Papelera
        </a>
        <div class="btn-group" role="group" aria-label="Exportar el cronograma">
            <a href="{{ route('content.projects.contents.export.excel', $project).$exportQuery }}"
               class="btn btn-outline-success" data-testid="btn-content-export-excel">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i> Exportar a Excel
            </a>
            <a href="{{ route('content.projects.contents.export.pdf', $project).$exportQuery }}"
               class="btn btn-outline-danger" data-testid="btn-content-export-pdf">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i> Exportar a PDF
            </a>
        </div>
        @if ($canManage)
            <a href="{{ route('content.projects.contents.create', $project) }}" class="btn btn-primary" data-testid="btn-create-content-item">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo contenido
            </a>
        @endif
    </div>

    @if ($errors->any())
        {{-- A refused filter (for example an impossible month) was dropped. --}}
        <div class="alert alert-warning" role="alert" data-testid="content-items-filter-errors">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <x-table title="Contenidos de {{ $project->name }}" data-testid="content-items-table">
        @slot('filters')
            <form method="GET" action="{{ route('content.projects.contents.index', $project) }}"
                  class="d-flex flex-wrap align-items-end gap-2" data-testid="content-items-filters">
                <div>
                    <label for="q" class="form-label mb-0 small">Buscar</label>
                    <input type="search" id="q" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                           placeholder="Título o copy">
                </div>
                <div>
                    <label for="month" class="form-label mb-0 small">Mes</label>
                    <input type="month" id="month" name="month" value="{{ $filters['month'] }}" class="form-control form-control-sm">
                </div>
                <div>
                    <label for="status" class="form-label mb-0 small">Estado</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach (\App\Enums\Content\ContentItemStatus::labels() as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($shows('pillar'))
                <div>
                    <label for="pillar" class="form-label mb-0 small">Pilar</label>
                    <select id="pillar" name="pillar" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['pillars'] as $pillar)
                            <option value="{{ $pillar->id }}" @selected((string) $filters['pillar'] === (string) $pillar->id)>{{ $pillar->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if ($shows('topic'))
                <div>
                    <label for="topic" class="form-label mb-0 small">Tema</label>
                    <select id="topic" name="topic" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['topics'] as $topic)
                            <option value="{{ $topic->id }}" @selected((string) $filters['topic'] === (string) $topic->id)>{{ $topic->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if ($shows('formats'))
                <div>
                    <label for="format" class="form-label mb-0 small">Formato</label>
                    <select id="format" name="format" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['formats'] as $format)
                            <option value="{{ $format->id }}" @selected((string) $filters['format'] === (string) $format->id)>{{ $format->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if ($shows('channels'))
                <div>
                    <label for="channel" class="form-label mb-0 small">Red social o canal</label>
                    <select id="channel" name="channel" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['channels'] as $channel)
                            <option value="{{ $channel->id }}" @selected((string) $filters['channel'] === (string) $channel->id)>{{ $channel->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('content.projects.contents.index', $project) }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th scope="col">Fecha</th>
                <th scope="col">Día</th>
                <th scope="col">Horario</th>
                @if ($shows('pillar'))<th scope="col">Pilar de contenido</th>@endif
                @if ($shows('topic'))<th scope="col">Tema</th>@endif
                <th scope="col">Estado</th>
                @if ($shows('objectives'))<th scope="col">Objetivos</th>@endif
                @if ($shows('formats'))<th scope="col">Formato</th>@endif
                @if ($shows('channels'))<th scope="col">Red social o canal</th>@endif
                <th scope="col">Gancho o título</th>
                <th scope="col">Descripción o copy</th>
                @if ($shows('hashtags'))<th scope="col">Hashtags</th>@endif
                @if ($shows('notes'))<th scope="col">Notas</th>@endif
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($contents as $content)
                <tr data-testid="content-item-row-{{ $content->id }}" @class(['table-warning' => $content->isOverdue()])>
                    <td class="text-nowrap">
                        {{ $content->planned_at ? \App\Support\Content\PeruTime::displayDate($content->planned_at) : '—' }}
                        @if ($content->isOverdue())
                            <span class="badge text-bg-danger" data-testid="content-item-overdue-{{ $content->id }}">Atrasado</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $content->planned_at ? \App\Support\Content\PeruTime::weekdayLabel($content->planned_at) : '—' }}</td>
                    <td class="text-nowrap">{{ $content->planned_at ? \App\Support\Content\PeruTime::toPeru($content->planned_at)->format('H:i') : '—' }}</td>
                    @if ($shows('pillar'))<td>{{ $content->pillar?->name ?? '—' }}</td>@endif
                    @if ($shows('topic'))<td>{{ $content->topic?->name ?? '—' }}</td>@endif
                    <td>
                        <span class="badge {{ $statusClasses[$content->status->value] ?? 'text-bg-secondary' }}">
                            {{ $content->status->label() }}
                        </span>
                    </td>
                    @if ($shows('objectives'))<td>{{ $content->objectives->pluck('name')->implode(', ') ?: '—' }}</td>@endif
                    @if ($shows('formats'))<td>{{ $content->formats->pluck('name')->implode(', ') ?: '—' }}</td>@endif
                    @if ($shows('channels'))<td>{{ $content->channels->pluck('name')->implode(', ') ?: '—' }}</td>@endif
                    <td>
                        <a href="{{ route('content.projects.contents.show', [$project, $content]) }}" class="fw-medium">{{ $content->title }}</a>
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($content->body, 80) }}</td>
                    @if ($shows('hashtags'))<td>{{ $content->hashtags ?: '—' }}</td>@endif
                    @if ($shows('notes'))<td>{{ $content->notes ?: '—' }}</td>@endif
                    <td class="text-end text-nowrap">
                        <a href="{{ route('content.projects.contents.show', [$project, $content]) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i> Ver
                        </a>
                        @can('update', $project)
                            <a href="{{ route('content.projects.contents.edit', [$project, $content]) }}" class="btn btn-sm btn-outline-secondary" title="Editar"
                               data-testid="content-item-edit-{{ $content->id }}">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                            </a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $columnCount }}">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay contenidos que coincidan con los filtros.',
                            'hint' => 'Cree el primero con el botón "Nuevo contenido".',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $contents])
        @endslot
    </x-table>
@endsection
