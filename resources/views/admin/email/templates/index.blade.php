@extends('layouts.app')

@section('title', 'Plantillas de correo')
@section('page-title', 'Plantillas de correo')

@section('content')
    <p class="text-muted">
        Catálogo de plantillas de correo (B13). Las plantillas son editables,
        versionadas y pueden probarse con un envío de prueba antes de salir a
        producción. Las variables se evalúan contra una lista permitida.
    </p>

    <x-table title="Plantillas">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Slug</th>
                <th>Activa</th>
                <th>Versión</th>
                <th>Variables</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($templates as $template)
                <tr>
                    <td>{{ $template->id }}</td>
                    <td>{{ $template->name }}</td>
                    <td><code>{{ $template->slug }}</code></td>
                    <td>
                        @if ($template->is_active)
                            <span class="badge bg-success">Sí</span>
                        @else
                            <span class="badge bg-secondary">No</span>
                        @endif
                    </td>
                    <td>{{ $template->version }}</td>
                    <td>
                        @forelse ($template->variables_json ?? [] as $var)
                            <code>{{ $var }}</code>
                            @if (! $loop->last) , @endif
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </td>
                    <td class="text-end text-nowrap">
                        @can('email.template.manage')
                            <a href="{{ route('admin.email.templates.edit', $template) }}"
                               class="btn btn-sm btn-outline-primary">
                                Editar
                            </a>
                            <form method="POST"
                                  action="{{ route('admin.email.templates.destroy', $template) }}"
                                  class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('¿Enviar la plantilla «{{ $template->name }}» a la papelera?')">
                                    Papelera
                                </button>
                            </form>
                        @else
                            <span class="text-muted">—</span>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        No hay plantillas registradas todavía.
                    </td>
                </tr>
            @endforelse

            @if (method_exists($templates, 'links'))
                <tr>
                    <td colspan="7">{{ $templates->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>

    @can('email.template.manage')
        <a href="{{ route('admin.email.templates.create') }}"
           class="btn btn-primary mt-3">
            <i class="bi bi-plus-circle me-1"></i>
            Nueva plantilla
        </a>
    @endcan
@endsection
