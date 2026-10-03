@extends('layouts.app')

@section('title', $project->name)
@section('page-title', $project->name)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al listado
        </a>

        @can('update', $project)
            <a href="{{ route('content.catalogs.index', $project) }}" class="btn btn-outline-primary">Administrar catálogos</a>
            <a href="{{ route('content.fields.index', $project) }}" class="btn btn-outline-primary">Campos personalizados</a>
            <a href="{{ route('content.projects.edit', $project) }}" class="btn btn-outline-primary" data-testid="btn-edit-content-project">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
        @endcan

        @if ($project->isArchived())
            @can('archive', $project)
                <form method="POST" action="{{ route('content.projects.reactivate', $project) }}" class="d-inline" data-testid="content-project-reactivate-form">
                    @csrf
                    <button type="submit" class="btn btn-outline-success">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Reactivar
                    </button>
                </form>
            @endcan
        @else
            @can('archive', $project)
                <x-swal-confirm
                    :action="route('content.projects.archive', $project)"
                    method="POST"
                    title="¿Archivar proyecto?"
                    text="El proyecto dejará de aparecer en el listado por defecto, pero sus miembros podrán seguir consultándolo."
                    type="warning"
                    confirm-text="Sí, archivar"
                    button-class="btn-outline-warning">
                    <i class="bi bi-archive me-1" aria-hidden="true"></i> Archivar
                </x-swal-confirm>
            @endcan
        @endif
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Detalle del proyecto</h3>
                    @if ($project->isArchived())
                        <span class="badge text-bg-warning" data-testid="content-project-archived-badge">Archivado</span>
                    @else
                        <x-badge-status :status="$project->is_active ? 'active' : 'inactive'"/>
                    @endif
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Identificador</dt>
                        <dd class="col-sm-9"><code>{{ $project->slug }}</code></dd>

                        @if ($project->color)
                            <dt class="col-sm-3">Color</dt>
                            <dd class="col-sm-9"><code>{{ $project->color }}</code></dd>
                        @endif

                        <dt class="col-sm-3">Descripción</dt>
                        <dd class="col-sm-9">{{ $project->description ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            @include('content.projects._members', ['project' => $project, 'memberCandidates' => $memberCandidates])
        </div>
    </div>
@endsection
