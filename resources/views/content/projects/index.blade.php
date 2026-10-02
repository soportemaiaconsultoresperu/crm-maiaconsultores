@extends('layouts.app')

@section('title', 'Cronograma de contenidos')
@section('page-title', 'Cronograma de contenidos')

@section('content')
    @php
        // Presentation only: whether the actor may create a project is the
        // policy's answer, and the per-row edit link repeats the same check.
        $canCreate = Gate::allows('create', App\Models\Content\ContentProject::class);
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        @if ($canCreate)
            <a href="{{ route('content.projects.create') }}" class="btn btn-primary" data-testid="btn-create-content-project">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo proyecto
            </a>
        @endif
    </div>

    <x-table title="Proyectos de contenido" data-testid="content-projects-table">
        @slot('filters')
            <form method="GET" action="{{ route('content.projects.index') }}" class="d-flex flex-wrap align-items-center gap-2" data-testid="content-projects-filters">
                <div class="form-check mb-0">
                    <input type="checkbox" class="form-check-input" id="include_archived" name="include_archived" value="1"
                           @checked($filters['include_archived'])>
                    <label class="form-check-label" for="include_archived">Incluir archivados</label>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                <a href="{{ route('content.projects.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th scope="col">Proyecto</th>
                <th scope="col">Identificador</th>
                <th scope="col">Miembros</th>
                <th scope="col">Estado</th>
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($projects as $project)
                <tr data-testid="content-project-row-{{ $project->id }}">
                    <td>
                        <a href="{{ route('content.projects.show', $project) }}" class="fw-medium">{{ $project->name }}</a>
                    </td>
                    <td><code>{{ $project->slug }}</code></td>
                    <td>{{ $project->members_count }}</td>
                    <td>
                        @if ($project->isArchived())
                            <span class="badge text-bg-warning" data-testid="content-project-state-{{ $project->id }}">Archivado</span>
                        @else
                            <x-badge-status :status="$project->is_active ? 'active' : 'inactive'" data-testid="content-project-state-{{ $project->id }}"/>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('content.projects.show', $project) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i> Ver
                        </a>
                        @can('update', $project)
                            <a href="{{ route('content.projects.edit', $project) }}" class="btn btn-sm btn-outline-secondary" title="Editar"
                               data-testid="content-project-edit-{{ $project->id }}">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                            </a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay proyectos de contenido visibles para tu usuario.',
                            'hint' => 'Los proyectos se muestran sólo a sus miembros.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $projects])
        @endslot
    </x-table>
@endsection
