@extends('layouts.app')

@section('title', 'Papelera de '.$project->name)
@section('page-title', 'Papelera de '.$project->name)

@php
    // Presentation only: opening the trash is a read, so the link mirrors the
    // `view` ability the endpoint enforces, while the restore, purge and
    // empty-trash controls mirror the `trash` ability (members included,
    // refused on an archived project).
    $canManage = Gate::allows('trash', $project);
@endphp

@section('content')
    @include('content.projects._read_only_notice', ['project' => $project])

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.contents.index', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al cronograma
        </a>

        @if ($canManage && $contents->total() > 0)
            <x-swal-confirm
                :action="route('content.projects.contents.trash.empty', $project)"
                method="DELETE"
                title="¿Vaciar la papelera?"
                text="Se eliminarán definitivamente todos los contenidos e ideas de la papelera de este proyecto, con sus valores y sus archivos. Esta acción es irreversible."
                type="error"
                confirm-text="Sí, vaciar papelera"
                button-class="btn-outline-danger"
                data-testid="content-trash-empty">
                <i class="bi bi-trash3 me-1" aria-hidden="true"></i> Vaciar papelera
            </x-swal-confirm>
        @endif
    </div>

    <div class="alert alert-warning" role="alert" data-testid="content-trash-notice">
        Los contenidos de la papelera conservan sus valores y sus archivos. No se eliminan solos:
        sólo se borran definitivamente cuando un miembro del proyecto los elimina uno a uno o vacía la papelera.
    </div>

    <x-table title="Contenidos eliminados de {{ $project->name }}" data-testid="content-trash-table">
        @slot('headers')
            <tr>
                <th scope="col">Título</th>
                <th scope="col">Tipo</th>
                <th scope="col">Fecha planificada</th>
                <th scope="col">Eliminado el</th>
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($contents as $content)
                <tr data-testid="content-trash-row-{{ $content->id }}">
                    <td class="fw-medium">{{ $content->title }}</td>
                    <td>
                        @if ($content->isIdea())
                            <span class="badge text-bg-light">Idea</span>
                        @else
                            <span class="badge text-bg-secondary">Contenido</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $content->plannedDate() ?? '—' }}</td>
                    <td class="text-nowrap" data-testid="content-trash-deleted-at-{{ $content->id }}">
                        {{ $content->deleted_at ? \App\Support\Content\PeruTime::toPeru($content->deleted_at)->format('d/m/Y H:i') : '—' }}
                    </td>
                    <td class="text-end text-nowrap">
                        @if ($canManage)
                            <form method="POST" action="{{ route('content.projects.contents.restore', [$project, $content]) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success" data-testid="content-trash-restore-{{ $content->id }}">
                                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restaurar
                                </button>
                            </form>
                            <x-swal-confirm
                                :action="route('content.projects.contents.purge', [$project, $content])"
                                method="DELETE"
                                title="¿Eliminar definitivamente?"
                                text="Esta acción es irreversible. Se borrarán el contenido, sus valores y sus archivos del disco, y no se podrá restaurar."
                                type="error"
                                confirm-text="Sí, eliminar definitivamente"
                                button-class="btn-sm btn-outline-danger"
                                data-testid="content-trash-purge-{{ $content->id }}">
                                <i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar definitivamente
                            </x-swal-confirm>
                        @else
                            <span class="text-muted small">{{ $project->isArchived() ? 'Proyecto archivado: solo lectura.' : 'No tiene permiso para restaurar o eliminar definitivamente.' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        @include('layouts.partials.empty-state', [
                            'message' => 'La papelera está vacía.',
                            'hint' => 'Los contenidos que mueva a la papelera aparecerán aquí hasta que se restauren o se eliminen definitivamente.',
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
