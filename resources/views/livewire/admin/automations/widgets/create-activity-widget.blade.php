<div class="row g-2">
    <div class="col-md-3">
        <label for="create-activity-{{ $actionIndex }}-type" class="form-label small mb-1">Tipo</label>
        <select id="create-activity-{{ $actionIndex }}-type"
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
            class="form-control form-control-sm" wire:model="title">
    </div>
    <div class="col-md-3">
        <label for="create-activity-{{ $actionIndex }}-priority" class="form-label small mb-1">Prioridad</label>
        <select id="create-activity-{{ $actionIndex }}-priority"
            class="form-select form-select-sm" wire:model="priority">
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="media">Media</option>
            <option value="error">Error</option>
        </select>
    </div>
    <div class="col-md-6">
        <label for="create-activity-{{ $actionIndex }}-scheduled-at" class="form-label small mb-1">Programado</label>
        <input type="datetime-local" id="create-activity-{{ $actionIndex }}-scheduled-at"
            class="form-control form-control-sm" wire:model="scheduled_at">
    </div>
    <div class="col-md-3">
        <label for="create-activity-{{ $actionIndex }}-owner" class="form-label small mb-1">Owner</label>
        <select id="create-activity-{{ $actionIndex }}-owner"
            class="form-select form-select-sm" wire:model="owner_id">
            <option value="">— Sin owner —</option>
            @foreach ($this->visibleUsers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <button type="button" class="btn btn-sm btn-outline-primary mt-4"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar
        </button>
    </div>
    <div class="col-12">
        <label for="create-activity-{{ $actionIndex }}-description" class="form-label small mb-1">Descripción</label>
        <textarea id="create-activity-{{ $actionIndex }}-description" rows="2"
            class="form-control form-control-sm" wire:model="description"></textarea>
    </div>
</div>
