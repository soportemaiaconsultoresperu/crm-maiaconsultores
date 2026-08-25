<div class="row g-2">
    <div class="col-md-4">
        <label for="change-stage-{{ $actionIndex }}-slug" class="form-label small mb-1">Etapa de oportunidad</label>
        <select id="change-stage-{{ $actionIndex }}-slug"
            name="actions[{{ $actionIndex }}][payload_json][stage_slug]"
            class="form-select form-select-sm"
            wire:model="stage_slug">
            <option value="">— Seleccionar —</option>
            @foreach ($this->stages as $slug => $name)
                <option value="{{ $slug }}">{{ $name }}</option>
            @endforeach
        </select>
        <x-validation-error name="stage_slug" />
    </div>
    <div class="col-md-7">
        <label for="change-stage-{{ $actionIndex }}-note" class="form-label small mb-1">Nota interna del cambio</label>
        <textarea id="change-stage-{{ $actionIndex }}-note" rows="2"
            name="actions[{{ $actionIndex }}][payload_json][note]"
            class="form-control form-control-sm"
            wire:model="note"></textarea>
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de etapa">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
</div>
