@extends('layouts.app')

@section('title', 'Ideas de '.$project->name)
@section('page-title', 'Ideas de '.$project->name)

@php
    // Presentation only: the create form is a mutation, so its link mirrors the
    // same `update` ability the endpoint enforces.
    $canManage = Gate::allows('update', $project);
    // Optional standard fields the project hides (S2) get no column.
    $shows = fn (string $field): bool => ! $project->isStandardFieldHidden($field);
    $columnCount = 3 + count(array_intersect(['pillar', 'topic', 'hashtags', 'notes'], $project->visibleStandardFields()));
@endphp

@section('content')
    @includeIf('content.partials._project_band', ['project' => $project])
    @include('content.projects._read_only_notice', ['project' => $project])

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.contents.index', $project) }}" class="btn btn-outline-secondary" data-testid="btn-content-idea-schedule">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i> Ver cronograma
        </a>
        <a href="{{ route('content.projects.show', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al proyecto
        </a>
        @if ($canManage)
            <a href="{{ route('content.projects.ideas.create', $project) }}" class="btn btn-primary" data-testid="btn-create-content-idea">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva idea
            </a>
        @endif
    </div>

    <x-table title="Ideas de {{ $project->name }}" data-testid="content-ideas-table">
        @slot('headers')
            <tr>
                @if ($shows('pillar'))<th scope="col">Pilar de contenido</th>@endif
                @if ($shows('topic'))<th scope="col">Tema</th>@endif
                <th scope="col">Gancho o título</th>
                <th scope="col">Descripción o copy</th>
                @if ($shows('hashtags'))<th scope="col">Hashtags</th>@endif
                @if ($shows('notes'))<th scope="col">Notas</th>@endif
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($ideas as $idea)
                <tr data-testid="content-idea-row-{{ $idea->id }}">
                    @if ($shows('pillar'))<td>{{ $idea->pillar?->name ?? '—' }}</td>@endif
                    @if ($shows('topic'))<td>{{ $idea->topic?->name ?? '—' }}</td>@endif
                    <td>
                        <a href="{{ route('content.projects.ideas.show', [$project, $idea]) }}" class="fw-medium">{{ $idea->title }}</a>
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($idea->body, 80) }}</td>
                    @if ($shows('hashtags'))<td>{{ $idea->hashtags ?: '—' }}</td>@endif
                    @if ($shows('notes'))<td>{{ $idea->notes ?: '—' }}</td>@endif
                    <td class="text-end text-nowrap">
                        <a href="{{ route('content.projects.ideas.show', [$project, $idea]) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i> Ver
                        </a>
                        @can('update', $project)
                            <a href="{{ route('content.projects.ideas.edit', [$project, $idea]) }}" class="btn btn-sm btn-outline-secondary" title="Editar"
                               data-testid="content-idea-edit-{{ $idea->id }}">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                            </a>
                            <a href="{{ route('content.projects.ideas.show', [$project, $idea]) }}#convertir" class="btn btn-sm btn-outline-primary" title="Convertir en contenido"
                               data-testid="content-idea-convert-link-{{ $idea->id }}">
                                <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i> Convertir
                            </a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $columnCount }}">
                        @include('layouts.partials.empty-state', [
                            'message' => 'Todavía no hay ideas registradas.',
                            'hint' => 'Registre la primera propuesta con el botón "Nueva idea"; se guardará sin fecha hasta que la convierta en contenido.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $ideas])
        @endslot
    </x-table>
@endsection
