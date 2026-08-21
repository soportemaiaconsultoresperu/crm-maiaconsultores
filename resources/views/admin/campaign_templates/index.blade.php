@extends('layouts.app')

@section('title', 'Plantillas de campañas')
@section('page-title', 'Plantillas de campañas')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\CampaignTemplate::class)
            <a href="{{ route('admin.campaign_templates.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Nueva plantilla
            </a>
        @endcan
    </div>

    <x-table title="Plantillas de campañas" data-testid="campaign-templates-table">
        @slot('headers')
            <tr>
                <th>Nombre</th>
                <th>Objetivo</th>
                <th>Estado</th>
                <th>Pasos</th>
                <th>Ejecuciones</th>
                <th>Responsable</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($templates as $template)
                <tr data-testid="template-row">
                    <td><a href="{{ route('admin.campaign_templates.show', $template) }}">{{ $template->name }}</a></td>
                    <td>{{ ucfirst($template->objective) }}</td>
                    <td>
                        <x-badge-status :status="$template->status === 'active' ? 'active' : 'inactive'" :text="ucfirst($template->status)"/>
                    </td>
                    <td>{{ $template->steps->count() }}</td>
                    <td>{{ $template->runs->count() }}</td>
                    <td>{{ $template->owner->name ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        @can('update', $template)
                            <a href="{{ route('admin.campaign_templates.edit', $template) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Editar
                            </a>
                            <form method="POST" action="{{ route('admin.campaign_templates.duplicate', $template) }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="new_name" value="{{ $template->name }} (copia)">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-files"></i> Duplicar
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.campaign_templates.destroy', $template) }}" class="d-inline"
                                  onsubmit="return confirm('¿Desactivar esta plantilla?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-circle"></i> Desactivar
                                </button>
                            </form>
                        @endcan
                        @can('create', App\Models\CampaignRun::class)
                            <a href="{{ route('admin.campaign_runs.create', ['template_id' => $template->id]) }}" class="btn btn-sm btn-success">
                                <i class="bi bi-play"></i> Crear ejecución
                            </a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @include('layouts.partials.empty-state', ['message' => 'Sin plantillas registradas.'])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $templates])
        @endslot
    </x-table>
@endsection
