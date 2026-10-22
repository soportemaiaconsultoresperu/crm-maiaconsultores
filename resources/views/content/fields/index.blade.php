@extends('layouts.app')

@section('title', 'Campos personalizados de '.$project->name)
@section('page-title', 'Campos personalizados de '.$project->name)

@section('content')
    @includeIf('content.partials._project_band', ['project' => $project])

    <a href="{{ route('content.projects.show', $project) }}" class="btn btn-outline-secondary mb-3">Volver al proyecto</a>

    <div class="alert alert-info" role="note">
        <p class="mb-1">Configure aquí las secciones y los campos propios de este proyecto, sin tocar código.</p>
        <p class="mb-0">La <strong>clave</strong> y el <strong>tipo</strong> de un campo quedan fijos al crearlo: los valores guardados dependen de ellos, por eso no se ofrecen como controles editables. Los elementos retirados se conservan y siguen visibles; nada se elimina de forma definitiva.</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @include('content.fields._standard_fields', ['project' => $project])

    <section class="card mb-3" aria-labelledby="heading-nueva-seccion">
        <div class="card-header"><h2 class="card-title mb-0" id="heading-nueva-seccion">Nueva sección</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('content.fields.sections.store', $project) }}" class="row g-3">
                @csrf
                <div class="col-md-4">
                    <label for="new-section-name" class="form-label">Nombre</label>
                    <input id="new-section-name" name="name" required maxlength="150" class="form-control">
                </div>
                <div class="col-md-4">
                    <label for="new-section-slug" class="form-label">Identificador</label>
                    <input id="new-section-slug" name="slug" required maxlength="150" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" aria-describedby="new-section-slug-help">
                    <small id="new-section-slug-help">Minúsculas, números y guiones. No se puede cambiar después.</small>
                </div>
                <div class="col-md-2">
                    <label for="new-section-sort" class="form-label">Orden</label>
                    <input id="new-section-sort" type="number" name="sort" value="0" min="0" max="2147483647" required class="form-control">
                </div>
                <div class="col-12"><button type="submit" class="btn btn-primary">Crear sección</button></div>
            </form>
        </div>
    </section>

    @forelse ($sections as $section)
        @include('content.fields._section', [
            'project' => $project,
            'section' => $section,
            'sectionFields' => $fieldsBySection[$section->id] ?? collect(),
            'sections' => $sections,
            'fieldTypes' => $fieldTypes,
        ])
    @empty
        <div class="alert alert-secondary" role="status">Todavía no hay secciones. Puede crear campos sin sección más abajo.</div>
    @endforelse

    <section class="card mb-3" aria-labelledby="heading-sin-seccion">
        <div class="card-header"><h2 class="card-title mb-0" id="heading-sin-seccion">Campos sin sección</h2></div>
        <div class="card-body">
            @forelse ($ungroupedFields as $field)
                @include('content.fields._field', [
                    'project' => $project,
                    'field' => $field,
                    'sections' => $sections,
                ])
            @empty
                <p class="text-muted">No hay campos sin sección.</p>
            @endforelse

            @include('content.fields._create_field', [
                'project' => $project,
                'section' => null,
                'sections' => $sections,
                'fieldTypes' => $fieldTypes,
            ])
        </div>
    </section>
@endsection
