@extends('layouts.app')

@section('title', $template->name)
@section('page-title', "Plantilla: {$template->name}")

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-0">{{ $template->name }}</h2>
            <small class="text-secondary">Plantilla de campaña</small>
        </div>
        <div class="btn-group">
            @can('create', App\Models\CampaignRun::class)
                <a href="{{ route('admin.campaign_runs.create', ['template_id' => $template->id]) }}" class="btn btn-success">
                    <i class="bi bi-play me-1"></i> Crear ejecución
                </a>
            @endcan
            @can('update', $template)
                <a href="{{ route('admin.campaign_templates.edit', $template) }}" class="btn btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i> Editar
                </a>
                <form method="POST" action="{{ route('admin.campaign_templates.duplicate', $template) }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="new_name" value="{{ $template->name }} (copia)">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-files me-1"></i> Duplicar
                    </button>
                </form>
                @if ($template->status === \App\Models\CampaignTemplate::STATUS_ACTIVE)
                    <form method="POST" action="{{ route('admin.campaign_templates.destroy', $template) }}" class="d-inline"
                          onsubmit="return confirm('¿Desactivar esta plantilla?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-x-circle me-1"></i> Desactivar
                        </button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    @if (session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Datos básicos</h3></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Estado</dt>
                <dd class="col-sm-9"><x-badge-status :status="$template->status" /></dd>

                <dt class="col-sm-3">Objetivo</dt>
                <dd class="col-sm-9">{{ ucfirst($template->objective) }}</dd>

                <dt class="col-sm-3">Responsable</dt>
                <dd class="col-sm-9">{{ $template->owner->name ?? '—' }}</dd>

                <dt class="col-sm-3">Equipo</dt>
                <dd class="col-sm-9">{{ $template->team->name ?? '—' }}</dd>

                <dt class="col-sm-3">Descripción</dt>
                <dd class="col-sm-9">{{ $template->description ?: '—' }}</dd>

                <dt class="col-sm-3">Pasos definidos</dt>
                <dd class="col-sm-9">{{ $template->steps->count() }}</dd>

                <dt class="col-sm-3">Ejecuciones</dt>
                <dd class="col-sm-9">{{ $template->runs->count() }}</dd>

                <dt class="col-sm-3">Creada</dt>
                <dd class="col-sm-9">{{ $template->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                <dt class="col-sm-3">Última actualización</dt>
                <dd class="col-sm-9">{{ $template->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Pasos de la plantilla</h3></div>
        <div class="card-body">
            @if ($template->steps->isEmpty())
                <p class="text-secondary mb-0">Esta plantilla no tiene pasos definidos.</p>
            @else
                <x-table>
                    @slot('headers')
                        <tr>
                            <th style="width: 4rem;">#</th>
                            <th>Título</th>
                            <th>Día</th>
                            <th>Hora</th>
                            <th>Instrucciones</th>
                        </tr>
                    @endslot
                    @slot('rows')
                        @foreach ($template->steps as $i => $step)
                            <tr>
                                <td>{{ $step->order ?? ($i + 1) }}</td>
                                <td>{{ $step->title }}</td>
                                <td>{{ $step->day_offset }}</td>
                                <td>{{ $step->scheduled_time ? \Carbon\Carbon::parse($step->scheduled_time)->format('H:i') : '—' }}</td>
                                <td>{{ $step->instructions ?: '—' }}</td>
                            </tr>
                        @endforeach
                    @endslot
                </x-table>
            @endif
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('admin.campaign_templates.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Volver al listado
        </a>
    </div>
@endsection