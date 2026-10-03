@extends('layouts.app')

@section('title', $idea->title)
@section('page-title', $idea->title)

@php
    $renderValue = function ($field, $value): string {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if ($field->type === \App\Enums\Content\ContentFieldType::SingleSelection) {
            return (string) ($field->options->firstWhere('id', (int) $value)?->label ?? '—');
        }

        if ($field->type === \App\Enums\Content\ContentFieldType::MultipleSelection) {
            return collect((array) $value)
                ->map(fn ($id): string => (string) ($field->options->firstWhere('id', (int) $id)?->label ?? '—'))
                ->implode(', ') ?: '—';
        }

        return (string) $value;
    };
@endphp

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('content.projects.ideas.index', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a las ideas
        </a>
        @can('update', $project)
            <a href="{{ route('content.projects.ideas.edit', [$project, $idea]) }}" class="btn btn-primary" data-testid="btn-edit-content-idea">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
        @endcan
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Detalle de la idea</h3>
            <span class="badge text-bg-secondary">Idea</span>
        </div>
        <div class="card-body">
            <p class="text-secondary small mb-3">Esta idea todavía no tiene fecha ni horario y no aparece en el cronograma de contenidos.</p>
            <dl class="row mb-0">
                <dt class="col-sm-3">Pilar de contenido</dt>
                <dd class="col-sm-9">{{ $idea->pillar?->name ?? '—' }}</dd>

                <dt class="col-sm-3">Tema</dt>
                <dd class="col-sm-9">{{ $idea->topic?->name ?? '—' }}</dd>

                <dt class="col-sm-3">Objetivos</dt>
                <dd class="col-sm-9">{{ $idea->objectives->pluck('name')->implode(', ') ?: '—' }}</dd>

                <dt class="col-sm-3">Formato</dt>
                <dd class="col-sm-9">{{ $idea->formats->pluck('name')->implode(', ') ?: '—' }}</dd>

                <dt class="col-sm-3">Red social o canal</dt>
                <dd class="col-sm-9">{{ $idea->channels->pluck('name')->implode(', ') ?: '—' }}</dd>

                <dt class="col-sm-3">Gancho o título</dt>
                <dd class="col-sm-9">{{ $idea->title }}</dd>

                <dt class="col-sm-3">Descripción o copy</dt>
                <dd class="col-sm-9" style="white-space: pre-line;">{{ $idea->body }}</dd>

                <dt class="col-sm-3">Hashtags</dt>
                <dd class="col-sm-9">{{ $idea->hashtags ?: '—' }}</dd>

                <dt class="col-sm-3">Notas</dt>
                <dd class="col-sm-9" style="white-space: pre-line;">{{ $idea->notes ?: '—' }}</dd>
            </dl>
        </div>
    </div>

    @php
        $hasCustomFields = $form['ungroupedFields']->isNotEmpty()
            || $form['fieldsBySection']->flatten()->isNotEmpty();
    @endphp

    @if ($hasCustomFields)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Campos personalizados</h3></div>
            <div class="card-body">
                @foreach ($form['sections'] as $section)
                    @php $sectionFields = $form['fieldsBySection'][$section->id] ?? collect(); @endphp
                    @if ($sectionFields->isNotEmpty())
                        <h4 class="h6">{{ $section->name }}</h4>
                        <dl class="row">
                            @foreach ($sectionFields as $field)
                                <dt class="col-sm-3">{{ $field->label }}</dt>
                                <dd class="col-sm-9">{{ $renderValue($field, $form['values'][$field->key] ?? null) }}</dd>
                            @endforeach
                        </dl>
                    @endif
                @endforeach

                @if ($form['ungroupedFields']->isNotEmpty())
                    <h4 class="h6">Otros campos</h4>
                    <dl class="row mb-0">
                        @foreach ($form['ungroupedFields'] as $field)
                            <dt class="col-sm-3">{{ $field->label }}</dt>
                            <dd class="col-sm-9">{{ $renderValue($field, $form['values'][$field->key] ?? null) }}</dd>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>
    @endif

    @can('update', $project)
        <div class="card border-primary" id="convertir" data-testid="content-idea-convert-card">
            <div class="card-header bg-primary-subtle">
                <h3 class="card-title mb-0">Convertir en contenido</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary">
                    Indique la fecha y el horario en hora de Perú. La idea se convertirá en un contenido del cronograma
                    conservando su texto, notas, hashtags, selecciones y campos personalizados.
                </p>
                <form method="POST" action="{{ route('content.projects.ideas.convert', [$project, $idea]) }}"
                      data-testid="content-idea-convert-form">
                    @csrf
                    <div class="row g-3 align-items-start">
                        <div class="col-md-4">
                            <x-text-input name="planned_date" type="date" label="Fecha" :required="true"
                                          help="Fecha de publicación en hora de Perú."/>
                        </div>
                        <div class="col-md-4">
                            <x-text-input name="planned_time" type="time" label="Horario en Perú" :required="true"/>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary" data-testid="btn-convert-content-idea">
                                <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i> Convertir en contenido
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
