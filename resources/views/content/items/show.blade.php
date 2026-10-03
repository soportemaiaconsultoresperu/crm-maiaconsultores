@extends('layouts.app')

@section('title', $item->title)
@section('page-title', $item->title)

@php
    $statusClasses = [
        \App\Enums\Content\ContentItemStatus::Incomplete->value => 'text-bg-secondary',
        \App\Enums\Content\ContentItemStatus::InProgress->value => 'text-bg-info',
        \App\Enums\Content\ContentItemStatus::CompletePendingPublish->value => 'text-bg-warning',
        \App\Enums\Content\ContentItemStatus::Published->value => 'text-bg-success',
    ];

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
        <a href="{{ route('content.projects.contents.index', $project) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al cronograma
        </a>
        @can('update', $project)
            <a href="{{ route('content.projects.contents.edit', [$project, $item]) }}" class="btn btn-primary" data-testid="btn-edit-content-item">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
            <form method="POST" action="{{ route('content.projects.contents.duplicate', [$project, $item]) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-secondary" data-testid="btn-duplicate-content-item">
                    <i class="bi bi-files me-1" aria-hidden="true"></i> Duplicar
                </button>
            </form>
        @endcan
        @can('archive', $project)
            <x-swal-confirm
                :action="route('content.projects.contents.destroy', [$project, $item])"
                method="DELETE"
                title="¿Mover a la papelera?"
                text="El contenido dejará de aparecer en el cronograma y en las ideas, pero conservará sus valores y sus archivos hasta que un administrador vacíe la papelera."
                type="warning"
                confirm-text="Sí, mover a la papelera"
                button-class="btn-outline-warning"
                data-testid="btn-trash-content-item">
                <i class="bi bi-trash me-1" aria-hidden="true"></i> Mover a la papelera
            </x-swal-confirm>
        @endcan
    </div>

    @can('update', $project)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Reprogramar contenido</h3></div>
            <div class="card-body">
                <form method="POST"
                      action="{{ route('content.projects.contents.reschedule', [$project, $item]) }}"
                      class="row g-2 align-items-end"
                      data-testid="content-item-reschedule-form">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="revision" value="{{ $item->revision }}">
                    <div class="col-md-4">
                        <x-text-input name="planned_date" type="date" label="Nueva fecha" :value="old('planned_date', $item->plannedDate())" :required="true"
                                      help="Fecha de publicación en hora de Perú."/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="planned_time" type="time" label="Horario en Perú"
                                      :value="old('planned_time', $item->planned_at ? \App\Support\Content\PeruTime::toPeru($item->planned_at)->format('H:i') : null)" :required="true"/>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary" data-testid="btn-reschedule-content-item">
                            <i class="bi bi-calendar-event me-1" aria-hidden="true"></i> Reprogramar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Detalle del contenido</h3>
            <div class="d-flex gap-2">
                @if ($item->isOverdue())
                    <span class="badge text-bg-danger" data-testid="content-item-overdue-badge">Vencido</span>
                @endif
                <span class="badge {{ $statusClasses[$item->status->value] ?? 'text-bg-secondary' }}">{{ $item->status->label() }}</span>
            </div>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Fecha</dt>
                <dd class="col-sm-9">{{ $item->plannedDate() ?? '—' }}</dd>

                <dt class="col-sm-3">Día</dt>
                <dd class="col-sm-9">{{ $item->planned_at ? \App\Support\Content\PeruTime::weekdayLabel($item->planned_at) : '—' }}</dd>

                <dt class="col-sm-3">Horario en Perú</dt>
                <dd class="col-sm-9">{{ $item->planned_at ? \App\Support\Content\PeruTime::toPeru($item->planned_at)->format('H:i') : '—' }}</dd>

                <dt class="col-sm-3">Pilar de contenido</dt>
                <dd class="col-sm-9">{{ $item->pillar?->name ?? '—' }}</dd>

                <dt class="col-sm-3">Tema</dt>
                <dd class="col-sm-9">{{ $item->topic?->name ?? '—' }}</dd>

                <dt class="col-sm-3">Objetivos</dt>
                <dd class="col-sm-9">{{ $item->objectives->pluck('name')->implode(', ') ?: '—' }}</dd>

                <dt class="col-sm-3">Formato</dt>
                <dd class="col-sm-9">{{ $item->formats->pluck('name')->implode(', ') ?: '—' }}</dd>

                <dt class="col-sm-3">Red social o canal</dt>
                <dd class="col-sm-9">{{ $item->channels->pluck('name')->implode(', ') ?: '—' }}</dd>

                <dt class="col-sm-3">Gancho o título</dt>
                <dd class="col-sm-9">{{ $item->title }}</dd>

                <dt class="col-sm-3">Descripción o copy</dt>
                <dd class="col-sm-9" style="white-space: pre-line;">{{ $item->body }}</dd>

                <dt class="col-sm-3">Hashtags</dt>
                <dd class="col-sm-9">{{ $item->hashtags ?: '—' }}</dd>

                <dt class="col-sm-3">Notas</dt>
                <dd class="col-sm-9" style="white-space: pre-line;">{{ $item->notes ?: '—' }}</dd>
            </dl>
        </div>
    </div>

    @php
        $hasCustomFields = $form['ungroupedFields']->isNotEmpty()
            || $form['fieldsBySection']->flatten()->isNotEmpty();
    @endphp

    @if ($hasCustomFields)
        <div class="card">
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

    @include('content.items._attachments', [
        'project' => $project,
        'item' => $item,
        'attachments' => $attachments,
        'attachmentLimits' => $attachmentLimits,
    ])
@endsection
