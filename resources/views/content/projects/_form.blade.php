{{--
    Shared content project create/edit form (T3). Expected data:
    $project (null on create).
--}}
@php
    $isEdit = $project !== null;
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
                <div class="col-md-4">
                    <x-text-input name="color" label="Color" :value="$project?->color" maxlength="16"
                                  placeholder="#0d6efd" help="Opcional. Color con el que se identifica el proyecto."/>
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
