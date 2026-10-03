{{--
    Shared content create/edit form (T6c). Expected data:
    $project, $item (null on create), $form (custom-field layout), $catalogOptions.
--}}
@php
    $isEdit = $item !== null;
    $plannedDate = old('planned_date', $item?->plannedDate());
    $plannedTime = old('planned_time', $item?->planned_at ? \App\Support\Content\PeruTime::toPeru($item->planned_at)->format('H:i') : null);
    $selectedObjectives = old('objectives', $item?->objectives->pluck('id')->all() ?? []);
    $selectedFormats = old('formats', $item?->formats->pluck('id')->all() ?? []);
    $selectedChannels = old('channels', $item?->channels->pluck('id')->all() ?? []);
@endphp

<form method="POST"
      action="{{ $isEdit ? route('content.projects.contents.update', [$project, $item]) : route('content.projects.contents.store', $project) }}"
      @if ($isEdit) @method('PUT') @endif
      data-testid="content-item-form">
    @csrf

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

                <div class="col-md-4">
                    <x-select name="content_pillar_id" label="Pilar de contenido" placeholder="Sin pilar"
                              :options="$catalogOptions['pillars']->pluck('name', 'id')->all()"
                              :value="$item?->content_pillar_id"/>
                </div>
                <div class="col-md-4">
                    <x-select name="content_topic_id" label="Tema" placeholder="Sin tema"
                              :options="$catalogOptions['topics']->pluck('name', 'id')->all()"
                              :value="$item?->content_topic_id"
                              help="El tema debe pertenecer al pilar elegido."/>
                </div>

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

                <div class="col-md-6">
                    <x-label for="objectives" label="Objetivos"/>
                    <select name="objectives[]" id="objectives" multiple size="5"
                            class="form-select @error('objectives') is-invalid @enderror">
                        @foreach ($catalogOptions['objectives'] as $objective)
                            <option value="{{ $objective->id }}" @selected(in_array($objective->id, $selectedObjectives, true))>{{ $objective->name }}</option>
                        @endforeach
                    </select>
                    <x-validation-error name="objectives"/>
                </div>
                <div class="col-md-6">
                    <x-label for="formats" label="Formato"/>
                    <select name="formats[]" id="formats" multiple size="5"
                            class="form-select @error('formats') is-invalid @enderror">
                        @foreach ($catalogOptions['formats'] as $format)
                            <option value="{{ $format->id }}" @selected(in_array($format->id, $selectedFormats, true))>{{ $format->name }}</option>
                        @endforeach
                    </select>
                    <x-validation-error name="formats"/>
                </div>
                <div class="col-md-6">
                    <x-label for="channels" label="Red social o canal"/>
                    <select name="channels[]" id="channels" multiple size="5"
                            class="form-select @error('channels') is-invalid @enderror">
                        @foreach ($catalogOptions['channels'] as $channel)
                            <option value="{{ $channel->id }}" @selected(in_array($channel->id, $selectedChannels, true))>{{ $channel->name }}</option>
                        @endforeach
                    </select>
                    <x-validation-error name="channels"/>
                </div>

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
