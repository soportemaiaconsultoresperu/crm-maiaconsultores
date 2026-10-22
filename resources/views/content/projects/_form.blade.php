{{--
    Shared content project create/edit form (T3). Expected data:
    $project (null on create).
--}}
@php
    $isEdit = $project !== null;

    // Colour picker state (S1.1): a preset, "Sin color" or a custom hex. After
    // a failed submit the previous choice is restored from old input.
    $palette = \App\Support\Content\ProjectColor::PALETTE;
    $oldColor = old('color', $project?->color);
    $currentColor = $oldColor === 'custom'
        ? \App\Support\Content\ProjectColor::normalize(old('color_custom'))
        : \App\Support\Content\ProjectColor::normalize($oldColor);
    $isCustom = $oldColor === 'custom' || ($currentColor !== null && ! array_key_exists($currentColor, $palette));
    $customValue = $currentColor ?? \App\Support\Content\ProjectColor::DEFAULT;
@endphp

<form method="POST"
      action="{{ $isEdit ? route('content.projects.update', $project) : route('content.projects.store') }}"
      @if ($isEdit) @method('PUT') @endif
      data-testid="content-project-form">
    @csrf

    <div class="card">
        <div class="card-header">
            <h3 class="card-title mb-0">{{ $isEdit ? 'Editar proyecto' : 'Nuevo proyecto' }}</h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <x-text-input name="name" label="Nombre" :value="$project?->name" :required="true" maxlength="150"/>
                </div>
                <div class="col-md-6">
                    <x-text-input name="slug" label="Identificador" :value="$project?->slug" :required="true" maxlength="150"
                                  help="Minúsculas, números y guiones (ej. maia-consultores)."/>
                </div>
                <div class="col-12">
                    <fieldset data-testid="content-project-color-palette">
                        <legend class="form-label fs-6 mb-1">Color</legend>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <input type="radio" class="btn-check" name="color" id="color-none" value=""
                                   data-testid="content-project-color-option-none" @checked($currentColor === null && ! $isCustom)>
                            <label class="btn btn-sm btn-outline-secondary" for="color-none">Sin color</label>

                            @foreach ($palette as $hex => $label)
                                <input type="radio" class="btn-check" name="color" id="color-{{ ltrim($hex, '#') }}" value="{{ $hex }}"
                                       data-testid="content-project-color-option-{{ ltrim($hex, '#') }}" @checked(! $isCustom && $currentColor === $hex)>
                                <label class="content-color-swatch" for="color-{{ ltrim($hex, '#') }}"
                                       @style(['background-color: '.$hex]) title="{{ $label }}">
                                    <span class="visually-hidden">{{ $label }}</span>
                                </label>
                            @endforeach

                            <input type="radio" class="btn-check" name="color" id="color-custom" value="custom"
                                   data-testid="content-project-color-option-custom" @checked($isCustom)>
                            <label class="btn btn-sm btn-outline-secondary" for="color-custom">Personalizado</label>
                            <input type="color" name="color_custom" id="color_custom" value="{{ $customValue }}"
                                   class="form-control form-control-color" title="Elegir un color personalizado"
                                   aria-label="Color personalizado"
                                   oninput="document.getElementById('color-custom').checked = true">
                        </div>
                        <div class="form-text">Opcional. Identifica al proyecto en sus páginas, calendarios y exportaciones.</div>
                        <x-validation-error name="color"/>
                    </fieldset>
                </div>
                @if ($isEdit)
                    <div class="col-md-4">
                        <x-select name="is_active" label="Estado" :options="[1 => 'Activo', 0 => 'Inactivo']"
                                  :value="$project->is_active ? '1' : '0'"/>
                    </div>
                @endif
                <div class="col-12">
                    <x-label for="description" label="Descripción"/>
                    <textarea name="description" id="description" rows="3" maxlength="2000"
                              class="form-control @error('description') is-invalid @enderror"
                              placeholder="Objetivo del proyecto de contenidos">{{ old('description', $project?->description) }}</textarea>
                    <x-validation-error name="description"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-testid="btn-save-content-project">
                {{ $isEdit ? 'Guardar cambios' : 'Crear proyecto' }}
            </button>
            <a href="{{ $isEdit ? route('content.projects.show', $project) : route('content.projects.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>
