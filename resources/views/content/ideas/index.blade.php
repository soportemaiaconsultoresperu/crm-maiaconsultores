@extends('layouts.app')

@section('title', 'Ideas de '.$project->name)
@section('page-title', 'Ideas de '.$project->name)

@php
    // Presentation only: the create form is a mutation, so its link mirrors the
    // same `update` ability the endpoint enforces.
    $canManage = Gate::allows('update', $project);
@endphp

@section('content')
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
                <th scope="col">Pilar de contenido</th>
                <th scope="col">Tema</th>
                <th scope="col">Gancho o título</th>
                <th scope="col">Descripción o copy</th>
                <th scope="col">Hashtags</th>
                <th scope="col">Notas</th>
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($ideas as $idea)
                <tr data-testid="content-idea-row-{{ $idea->id }}">
                    <td>{{ $idea->pillar?->name ?? '—' }}</td>
                    <td>{{ $idea->topic?->name ?? '—' }}</td>
                    <td>
                        <a href="{{ route('content.projects.ideas.show', [$project, $idea]) }}" class="fw-medium">{{ $idea->title }}</a>
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($idea->body, 80) }}</td>
                    <td>{{ $idea->hashtags ?: '—' }}</td>
                    <td>{{ $idea->notes ?: '—' }}</td>
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
                    <td colspan="7">
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
