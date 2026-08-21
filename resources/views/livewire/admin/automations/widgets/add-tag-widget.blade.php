<div class="row g-2">
    <div class="col-md-4">
        <label for="action-{{ $actionIndex }}-tag-slug" class="form-label small mb-1">Slug del tag</label>
        <input type="text" id="action-{{ $actionIndex }}-tag-slug"
            class="form-control form-control-sm @error('tag_slug') is-invalid @enderror"
            wire:model="tag_slug">
        <x-validation-error name="tag_slug" />
    </div>
    <div class="col-md-4">
        <label for="action-{{ $actionIndex }}-tag-name" class="form-label small mb-1">Nombre (auto-crear)</label>
        <input type="text" id="action-{{ $actionIndex }}-tag-name"
            class="form-control form-control-sm"
            wire:model="tag_name">
    </div>
    <div class="col-md-3">
        <label for="action-{{ $actionIndex }}-tag-color" class="form-label small mb-1">Color</label>
        <input type="text" id="action-{{ $actionIndex }}-tag-color"
            class="form-control form-control-sm"
            wire:model="color">
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2" aria-hidden="true"></i>
        </button>
    </div>
</div>
