{{--
    Shared content create/edit form (T6c). Expected data:
    $project, $item (null on create), $form (custom-field layout), $catalogOptions.
--}}
@php
    $isEdit = $item !== null;
    $plannedDate = old('planned_date', $item?->plannedDate());
    $plannedTime = old('planned_time', $item?->planned_at ? \App\Support\Content\PeruTime::toPeru($item->planned_at)->format('H:i') : null);
@endphp

<form method="POST"
      action="{{ $isEdit ? route('content.projects.contents.update', [$project, $item]) : route('content.projects.contents.store', $project) }}"
      data-testid="content-item-form"
      data-swal-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    {{-- Optimistic-concurrency token (T8a): the revision this form loaded. It
         is ALWAYS the row's current revision, never the flashed old input: a
         refused save is exactly the case where the submitted revision is stale,
         so re-injecting it would trap the user in a permanent conflict. --}}
    @if ($isEdit)
        <input type="hidden" name="revision" value="{{ $item?->revision }}" data-testid="content-item-revision">
    @endif

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">{{ $isEdit ? 'Editar contenido' : 'Nuevo contenido' }}</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <x-text-input name="planned_date" type="date" label="Fecha" :value="$plannedDate" :required="true"
                                  help="Fecha de publicación en hora de Perú."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="planned_time" type="time" label="Horario en Perú" :value="$plannedTime" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-select name="status" label="Estado" :options="\App\Enums\Content\ContentItemStatus::labels()"
                              :value="$item?->status?->value" :required="true"/>
                </div>

                @include('content.items._catalog_fields', ['part' => 'pillar_topic', 'record' => $item, 'catalogOptions' => $catalogOptions])

                <div class="col-12">
                    <x-text-input name="title" label="Gancho o título" :value="$item?->title" :required="true" maxlength="200"/>
                </div>
                <div class="col-12">
                    <x-label for="body" label="Descripción o copy" :required="true"/>
                    <textarea name="body" id="body" rows="4" required
                              class="form-control @error('body') is-invalid @enderror"
                              placeholder="Texto de la publicación">{{ old('body', $item?->body) }}</textarea>
                    <x-validation-error name="body"/>
                </div>

                @include('content.items._catalog_fields', ['part' => 'multi', 'record' => $item, 'catalogOptions' => $catalogOptions])

                <div class="col-md-6">
                    <x-text-input name="hashtags" label="Hashtags" :value="$item?->hashtags" help="Opcional."/>
                </div>
                <div class="col-12">
                    <x-label for="notes" label="Notas"/>
                    <textarea name="notes" id="notes" rows="3"
                              class="form-control @error('notes') is-invalid @enderror"
                              placeholder="Notas internas">{{ old('notes', $item?->notes) }}</textarea>
                    <x-validation-error name="notes"/>
                </div>
            </div>
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
                        <fieldset class="mb-4">
                            <legend class="h6">{{ $section->name }}</legend>
                            <div class="row g-3">
                                @foreach ($sectionFields as $field)
                                    @include('content.items._custom_field', ['field' => $field, 'values' => $form['values']])
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                @endforeach

                @if ($form['ungroupedFields']->isNotEmpty())
                    <fieldset class="mb-2">
                        <legend class="h6">Otros campos</legend>
                        <div class="row g-3">
                            @foreach ($form['ungroupedFields'] as $field)
                                @include('content.items._custom_field', ['field' => $field, 'values' => $form['values']])
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            </div>
        </div>
    @endif

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" data-testid="btn-save-content-item">
            {{ $isEdit ? 'Guardar cambios' : 'Crear contenido' }}
        </button>
        <a href="{{ $isEdit ? route('content.projects.contents.show', [$project, $item]) : route('content.projects.contents.index', $project) }}"
           class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
