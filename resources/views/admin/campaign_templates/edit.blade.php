@extends('layouts.app')

@section('title', "Editar {$template->name}")
@section('page-title', "Editar plantilla: {$template->name}")

@section('content')
    @php
        $actionTypesJs = \App\Models\ActivityType::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->pluck('name', 'id')
            ->all();
        $steps = old('steps') ?? $template->steps->map(fn ($step) => [
            'action_type_id' => $step->action_type_id,
            'title'          => $step->title,
            'day_offset'     => $step->day_offset,
            'scheduled_time' => $step->scheduled_time ? \Carbon\Carbon::parse($step->scheduled_time)->format('H:i') : '09:00',
            'instructions'   => $step->instructions,
        ])->all();
        if (empty($steps)) {
            $steps = [[
                'action_type_id' => '',
                'title' => '',
                'day_offset' => 0,
                'scheduled_time' => '09:00',
                'instructions' => '',
            ]];
        }
    @endphp

    <form method="POST" action="{{ route('admin.campaign_templates.update', $template) }}">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Datos básicos</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <x-text-input name="name" label="Nombre" :required="true" :value="old('name', $template->name)"/>
                    </div>
                    <div class="col-md-6">
                        <x-label for="status" label="Estado"/>
                        <select name="status" id="status" class="form-select @error('status') is-invalid @enderror">
                            @foreach ([
                                \App\Models\CampaignTemplate::STATUS_DRAFT => 'Borrador',
                                \App\Models\CampaignTemplate::STATUS_ACTIVE => 'Activa',
                                \App\Models\CampaignTemplate::STATUS_INACTIVE => 'Inactiva',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $template->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-validation-error name="status"/>
                    </div>
                    <div class="col-12">
                        <x-label for="description" label="Descripción"/>
                        <textarea name="description" id="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $template->description) }}</textarea>
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
                <i class="bi bi-save me-1"></i> Actualizar plantilla
            </button>
            <a href="{{ route('admin.campaign_templates.show', $template) }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    @can('update', $template)
        @if ($template->status === \App\Models\CampaignTemplate::STATUS_ACTIVE)
            <form method="POST" action="{{ route('admin.campaign_templates.destroy', $template) }}" class="mt-3"
                  onsubmit="return confirm('¿Desactivar esta plantilla? Las ejecuciones históricas se mantienen.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x-circle me-1"></i> Desactivar plantilla
                </button>
            </form>
        @endif
    @endcan

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