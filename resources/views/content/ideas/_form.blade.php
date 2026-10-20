{{--
    Shared idea create/edit form (T6d). Expected data:
    $project, $idea (null on create), $form (custom-field layout), $catalogOptions.

    An idea has no planned date, no time and no status: it is a proposal that is
    only placed on the calendar by the conversion form on the detail screen.
--}}
@php
    $isEdit = $idea !== null;
@endphp

<form method="POST"
      action="{{ $isEdit ? route('content.projects.ideas.update', [$project, $idea]) : route('content.projects.ideas.store', $project) }}"
      data-testid="content-idea-form"
      data-swal-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
        {{-- Optimistic-concurrency token: always the row's current revision,
             never the flashed old input, as in the content form. --}}
        <input type="hidden" name="revision" value="{{ $idea->revision }}" data-testid="content-idea-revision">
    @endif

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">{{ $isEdit ? 'Editar idea' : 'Nueva idea' }}</h3></div>
        <div class="card-body">
            <p class="text-secondary small">Una idea se guarda sin fecha ni horario. Cuando esté lista podrá convertirla en un contenido del cronograma.</p>

            <div class="row g-3">
                @include('content.items._catalog_fields', ['part' => 'pillar_topic', 'record' => $idea, 'catalogOptions' => $catalogOptions])

                <div class="col-12">
                    <x-text-input name="title" label="Gancho o título" :value="$idea?->title" :required="true" maxlength="200"/>
                </div>
                <div class="col-12">
                    <x-label for="body" label="Descripción o copy" :required="true"/>
                    <textarea name="body" id="body" rows="4" required
                              class="form-control @error('body') is-invalid @enderror"
                              placeholder="Texto de la publicación">{{ old('body', $idea?->body) }}</textarea>
                    <x-validation-error name="body"/>
                </div>

                @include('content.items._catalog_fields', ['part' => 'multi', 'record' => $idea, 'catalogOptions' => $catalogOptions])

                @unless ($project->isStandardFieldHidden('hashtags'))
                <div class="col-md-6">
                    <x-text-input name="hashtags" label="Hashtags" :value="$idea?->hashtags" help="Opcional."/>
                </div>
                @endunless
                @unless ($project->isStandardFieldHidden('notes'))
                <div class="col-12">
                    <x-label for="notes" label="Notas"/>
                    <textarea name="notes" id="notes" rows="3"
                              class="form-control @error('notes') is-invalid @enderror"
                              placeholder="Notas internas">{{ old('notes', $idea?->notes) }}</textarea>
                    <x-validation-error name="notes"/>
                </div>
                @endunless
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
        <button type="submit" class="btn btn-primary" data-testid="btn-save-content-idea">
            {{ $isEdit ? 'Guardar cambios' : 'Guardar idea' }}
        </button>
        <a href="{{ $isEdit ? route('content.projects.ideas.show', [$project, $idea]) : route('content.projects.ideas.index', $project) }}"
           class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
