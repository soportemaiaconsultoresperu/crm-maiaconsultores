@extends('layouts.app')

@section('title', 'Editar plantilla de certificado')
@section('page-title', 'Editar plantilla de certificado')

@section('content')
    @php
        // The version and the active state are the domain's, not the form's: a
        // configuration change derives a new version, and whether this template
        // is the one in force is decided by the activate/deactivate actions on
        // the list. Both are shown here, neither is editable here.
        $scopeLabel = $typeScopes[$template->type_scope ?? ''] ?? ($template->type_scope ?? '—');
    @endphp

    <a href="{{ route('course-talks.templates.index') }}" class="btn btn-outline-secondary mb-3">Volver a plantillas</a>

    <div class="card mb-3" data-testid="course-talks-template-summary-{{ $template->id }}">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Plantilla</dt>
                <dd class="col-sm-9">{{ $template->name }}</dd>
                <dt class="col-sm-3">Alcance</dt>
                <dd class="col-sm-9">{{ $scopeLabel }}</dd>
                <dt class="col-sm-3">Versión</dt>
                <dd class="col-sm-9">v{{ $template->version }}</dd>
                <dt class="col-sm-3">Estado</dt>
                <dd class="col-sm-9">
                    <span class="badge {{ $template->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $template->is_active ? 'Activa' : 'Inactiva' }}
                    </span>
                    <span class="text-secondary small">{{ $template->is_active ? 'Los certificados de su alcance usan esta plantilla.' : 'Los certificados de su alcance usan la plantilla activa o los valores por defecto.' }}</span>
                </dd>
            </dl>
        </div>
    </div>

    @include('course-talks.templates._form', [
        'action' => route('course-talks.templates.update', $template),
        'verb' => 'PUT',
        'heading' => 'Configuración de la plantilla',
        'submitLabel' => 'Guardar cambios',
        'backUrl' => route('course-talks.templates.index'),
    ])
@endsection
