<div class="row g-2">
    <div class="col-md-4">
        <label for="change-status-{{ $actionIndex }}-column" class="form-label small mb-1">Columna</label>
        <select id="change-status-{{ $actionIndex }}-column"
            class="form-select form-select-sm"
            wire:model="column">
            <option value="status_id">status_id (Lead)</option>
            <option value="status">status (Customer)</option>
            <option value="stage_id">stage_id (Opportunity)</option>
        </select>
    </div>
    <div class="col-md-7">
        <label for="change-status-{{ $actionIndex }}-value" class="form-label small mb-1">Valor</label>
        <input type="text" id="change-status-{{ $actionIndex }}-value"
            class="form-control form-control-sm"
            wire:model="value">
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2" aria-hidden="true"></i>
        </button>
    </div>
</div>
