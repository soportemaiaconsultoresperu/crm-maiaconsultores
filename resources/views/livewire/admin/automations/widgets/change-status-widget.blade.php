<div class="row g-2">
    <div class="col-md-4">
        <label for="change-status-{{ $actionIndex }}-column" class="form-label small mb-1">Campo que se actualizará</label>
        <select id="change-status-{{ $actionIndex }}-column"
            name="actions[{{ $actionIndex }}][payload_json][column]"
            class="form-select form-select-sm"
            wire:model="column">
            <option value="status_id">Estado del prospecto</option>
            <option value="status">Estado del cliente</option>
            <option value="stage_id">Etapa de la oportunidad</option>
        </select>
        <div class="form-text">La regla conserva el valor técnico que necesita el CRM al guardar.</div>
    </div>
    <div class="col-md-7">
        <label for="change-status-{{ $actionIndex }}-value" class="form-label small mb-1">Nuevo valor</label>
        <input type="text" id="change-status-{{ $actionIndex }}-value"
            name="actions[{{ $actionIndex }}][payload_json][value]"
            class="form-control form-control-sm"
            wire:model="value">
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de estado">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
</div>
