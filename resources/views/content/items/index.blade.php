@extends('layouts.app')

@section('title', 'Cronograma de '.$project->name)
@section('page-title', 'Cronograma de '.$project->name)

@php
    // Presentation only: the create form is a mutation, so its link mirrors
    // the same `update` ability the endpoint enforces.
    $canManage = Gate::allows('update', $project);
    $statusClasses = [
        \App\Enums\Content\ContentItemStatus::Incomplete->value => 'text-bg-secondary',
        \App\Enums\Content\ContentItemStatus::InProgress->value => 'text-bg-info',
        \App\Enums\Content\ContentItemStatus::CompletePendingPublish->value => 'text-bg-warning',
        \App\Enums\Content\ContentItemStatus::Published->value => 'text-bg-success',
    ];
@endphp

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.show', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al proyecto
        </a>
        <a href="{{ route('content.projects.ideas.index', $project) }}" class="btn btn-outline-secondary" data-testid="btn-content-idea-section">
            <i class="bi bi-lightbulb me-1" aria-hidden="true"></i> Ver ideas
        </a>
        @if ($canManage)
            <a href="{{ route('content.projects.contents.create', $project) }}" class="btn btn-primary" data-testid="btn-create-content-item">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo contenido
            </a>
        @endif
    </div>

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
                <div>
                    <label for="pillar" class="form-label mb-0 small">Pilar</label>
                    <select id="pillar" name="pillar" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['pillars'] as $pillar)
                            <option value="{{ $pillar->id }}" @selected((string) $filters['pillar'] === (string) $pillar->id)>{{ $pillar->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="topic" class="form-label mb-0 small">Tema</label>
                    <select id="topic" name="topic" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['topics'] as $topic)
                            <option value="{{ $topic->id }}" @selected((string) $filters['topic'] === (string) $topic->id)>{{ $topic->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="format" class="form-label mb-0 small">Formato</label>
                    <select id="format" name="format" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['formats'] as $format)
                            <option value="{{ $format->id }}" @selected((string) $filters['format'] === (string) $format->id)>{{ $format->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="channel" class="form-label mb-0 small">Red social o canal</label>
                    <select id="channel" name="channel" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($catalogOptions['channels'] as $channel)
                            <option value="{{ $channel->id }}" @selected((string) $filters['channel'] === (string) $channel->id)>{{ $channel->name }}</option>
                        @endforeach
                    </select>
                </div>
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
                <th scope="col">Pilar de contenido</th>
                <th scope="col">Tema</th>
                <th scope="col">Estado</th>
                <th scope="col">Objetivos</th>
                <th scope="col">Formato</th>
                <th scope="col">Red social o canal</th>
                <th scope="col">Gancho o título</th>
                <th scope="col">Descripción o copy</th>
                <th scope="col">Hashtags</th>
                <th scope="col">Notas</th>
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($contents as $content)
                <tr data-testid="content-item-row-{{ $content->id }}" @class(['table-warning' => $content->isOverdue()])>
                    <td class="text-nowrap">
                        {{ $content->plannedDate() ?? '—' }}
                        @if ($content->isOverdue())
                            <span class="badge text-bg-danger" data-testid="content-item-overdue-{{ $content->id }}">Vencido</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $content->planned_at ? \App\Support\Content\PeruTime::weekdayLabel($content->planned_at) : '—' }}</td>
                    <td class="text-nowrap">{{ $content->planned_at ? \App\Support\Content\PeruTime::toPeru($content->planned_at)->format('H:i') : '—' }}</td>
                    <td>{{ $content->pillar?->name ?? '—' }}</td>
                    <td>{{ $content->topic?->name ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $statusClasses[$content->status->value] ?? 'text-bg-secondary' }}">
                            {{ $content->status->label() }}
                        </span>
                    </td>
                    <td>{{ $content->objectives->pluck('name')->implode(', ') ?: '—' }}</td>
                    <td>{{ $content->formats->pluck('name')->implode(', ') ?: '—' }}</td>
                    <td>{{ $content->channels->pluck('name')->implode(', ') ?: '—' }}</td>
                    <td>
                        <a href="{{ route('content.projects.contents.show', [$project, $content]) }}" class="fw-medium">{{ $content->title }}</a>
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($content->body, 80) }}</td>
                    <td>{{ $content->hashtags ?: '—' }}</td>
                    <td>{{ $content->notes ?: '—' }}</td>
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
                    <td colspan="14">
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
