<div class="row g-2">
    <div class="col-md-9">
        <label for="add-note-{{ $actionIndex }}-body" class="form-label small mb-1">Contenido de la nota <span class="text-danger">*</span></label>
        <textarea id="add-note-{{ $actionIndex }}-body" rows="3"
            name="actions[{{ $actionIndex }}][payload_json][body]"
            class="form-control form-control-sm @error('body') is-invalid @enderror"
            wire:model="body"></textarea>
        <x-validation-error name="body" />
        <div class="form-text">Se guardará como una nota en el historial. Si falta el tipo “nota”, el CRM lo creará al ejecutar la regla.</div>
    </div>
    <div class="col-md-2">
        <label for="add-note-{{ $actionIndex }}-priority" class="form-label small mb-1">Prioridad</label>
        <select id="add-note-{{ $actionIndex }}-priority"
            name="actions[{{ $actionIndex }}][payload_json][priority]"
            class="form-select form-select-sm" wire:model="priority">
            <option value="info">Informativa</option>
            <option value="warning">Alerta</option>
            <option value="media">Media</option>
            <option value="error">Crítica</option>
        </select>
    </div>
    <div class="col-md-6">
        <label for="add-note-{{ $actionIndex }}-owner" class="form-label small mb-1">Responsable</label>
        <select id="add-note-{{ $actionIndex }}-owner"
            name="actions[{{ $actionIndex }}][payload_json][owner_id]"
            class="form-select form-select-sm" wire:model="owner_id">
            <option value="">— Sin responsable —</option>
            @foreach ($this->visibleUsers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de nota">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
</div>
