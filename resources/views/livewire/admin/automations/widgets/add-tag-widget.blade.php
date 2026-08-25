<div class="row g-2">
    <div class="col-md-4">
        <label for="action-{{ $actionIndex }}-tag-slug" class="form-label small mb-1">Código de etiqueta</label>
        <input type="text" id="action-{{ $actionIndex }}-tag-slug"
            name="actions[{{ $actionIndex }}][payload_json][tag_slug]"
            class="form-control form-control-sm @error('tag_slug') is-invalid @enderror"
            wire:model="tag_slug">
        <x-validation-error name="tag_slug" />
        <div class="form-text">Identificador corto sin espacios. Ejemplo: cliente-vip.</div>
    </div>
    <div class="col-md-4">
        <label for="action-{{ $actionIndex }}-tag-name" class="form-label small mb-1">Nombre visible de la etiqueta</label>
        <input type="text" id="action-{{ $actionIndex }}-tag-name"
            name="actions[{{ $actionIndex }}][payload_json][tag_name]"
            class="form-control form-control-sm"
            wire:model="tag_name">
    </div>
    <div class="col-md-3">
        <label for="action-{{ $actionIndex }}-tag-color" class="form-label small mb-1">Color de referencia</label>
        <input type="text" id="action-{{ $actionIndex }}-tag-color"
            name="actions[{{ $actionIndex }}][payload_json][color]"
            class="form-control form-control-sm"
            wire:model="color">
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de etiqueta">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
</div>
