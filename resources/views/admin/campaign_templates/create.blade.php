@extends('layouts.app')

@section('title', 'Nueva plantilla de campaña')
@section('page-title', 'Nueva plantilla de campaña')

@section('content')
@php
        $actionTypesJs = \App\Models\ActivityType::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->pluck('name', 'id')
            ->all();
        $steps = old('steps') ?? [[
            'action_type_id' => '',
            'title' => '',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
            'instructions' => '',
        ]];
    @endphp

    <form method="POST" action="{{ route('admin.campaign_templates.store') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Datos básicos</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <x-text-input name="name" label="Nombre" :required="true" :value="old('name')"/>
                    </div>

                    <div class="col-12">
                        <x-label for="description" label="Descripción"/>
                        <textarea name="description" id="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                        <x-validation-error name="description"/>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Pasos de la campaña</h3></div>
            <div class="card-body">
                <p class="text-secondary small mb-3">
                    Cada paso representa una acción que se ejecutará para cada contacto. Define el orden (día relativo desde el inicio), hora, e instrucciones.
                </p>
                <div id="steps-container" data-testid="steps-container" data-action-types='@json($actionTypesJs)'>
                    @foreach ($steps as $i => $step)
                        @include('admin.campaign_templates.partials._step_row', ['i' => $i, 'step' => $step])
                    @endforeach
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="add-step-btn">
                    <i class="bi bi-plus-lg"></i> Agregar paso
                </button>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i> Crear plantilla
            </button>
            <a href="{{ route('admin.campaign_templates.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    @push('scripts')
        <script>
                const ACTION_TYPES = JSON.parse(document.getElementById('steps-container').dataset.actionTypes || '{}');
                document.getElementById('add-step-btn').addEventListener('click', function () {
                    const container = document.getElementById('steps-container');
                    const i = container.children.length;
                    let opts = '';
                    Object.entries(ACTION_TYPES).forEach(([id, name]) => {
                        opts += `<option value="${id}">${name}</option>`;
                    });
                    const html = `
                        <div class="card mb-2 p-3 step-row">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small">Tipo</label>
                                    <select name="steps[${i}][action_type_id]" class="form-select form-select-sm">${opts}</select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Título</label>
                                    <input type="text" name="steps[${i}][title]" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Día</label>
                                    <input type="number" name="steps[${i}][day_offset]" value="0" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Hora</label>
                                    <input type="time" name="steps[${i}][scheduled_time]" value="09:00" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-2 text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-step-btn"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    `;
                    container.insertAdjacentHTML('beforeend', html);
                });
                document.getElementById('steps-container').addEventListener('click', function (e) {
                    if (e.target.closest('.remove-step-btn')) {
                        e.target.closest('.step-row').remove();
                    }
                });
            </script>
    @endpush
@endsection
