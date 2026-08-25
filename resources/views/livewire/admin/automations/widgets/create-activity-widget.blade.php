<div class="row g-2">
    <div class="col-md-3">
        <label for="create-activity-{{ $actionIndex }}-type" class="form-label small mb-1">Tipo de actividad</label>
        <select id="create-activity-{{ $actionIndex }}-type"
            name="actions[{{ $actionIndex }}][payload_json][type_id]"
            class="form-select form-select-sm" wire:model="type_id">
            <option value="">— Seleccionar —</option>
            @foreach ($this->activityTypes as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label for="create-activity-{{ $actionIndex }}-title" class="form-label small mb-1">Título</label>
        <input type="text" id="create-activity-{{ $actionIndex }}-title"
            name="actions[{{ $actionIndex }}][payload_json][title]"
            class="form-control form-control-sm" wire:model="title">
    </div>
    <div class="col-md-3">
        <label for="create-activity-{{ $actionIndex }}-priority" class="form-label small mb-1">Prioridad</label>
        <select id="create-activity-{{ $actionIndex }}-priority"
            name="actions[{{ $actionIndex }}][payload_json][priority]"
            class="form-select form-select-sm" wire:model="priority">
            <option value="info">Informativa</option>
            <option value="warning">Alerta</option>
            <option value="media">Media</option>
            <option value="error">Crítica</option>
        </select>
    </div>
    <div class="col-md-6">
        <label for="create-activity-{{ $actionIndex }}-scheduled-at" class="form-label small mb-1">Fecha y hora programada</label>
        <input type="datetime-local" id="create-activity-{{ $actionIndex }}-scheduled-at"
            name="actions[{{ $actionIndex }}][payload_json][scheduled_at]"
            class="form-control form-control-sm" wire:model="scheduled_at">
    </div>
    <div class="col-md-3">
        <label for="create-activity-{{ $actionIndex }}-owner" class="form-label small mb-1">Responsable</label>
        <select id="create-activity-{{ $actionIndex }}-owner"
            name="actions[{{ $actionIndex }}][payload_json][owner_id]"
            class="form-select form-select-sm" wire:model="owner_id">
            <option value="">— Sin responsable —</option>
            @foreach ($this->visibleUsers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <button type="button" class="btn btn-sm btn-outline-primary mt-4"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de actividad">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
    <div class="col-12">
        <label for="create-activity-{{ $actionIndex }}-description" class="form-label small mb-1">Descripción</label>
        <textarea id="create-activity-{{ $actionIndex }}-description" rows="2"
            name="actions[{{ $actionIndex }}][payload_json][description]"
            class="form-control form-control-sm" wire:model="description"></textarea>
    </div>
</div>
