<div class="row g-2">
    <div class="col-md-9">
        <label for="add-note-{{ $actionIndex }}-body" class="form-label small mb-1">Cuerpo <span class="text-danger">*</span></label>
        <textarea id="add-note-{{ $actionIndex }}-body" rows="3"
            class="form-control form-control-sm @error('body') is-invalid @enderror"
            wire:model="body"></textarea>
        <x-validation-error name="body" />
        <div class="form-text">Si el ActivityType <code>nota</code> no existe, se creará automáticamente al ejecutar la regla.</div>
    </div>
    <div class="col-md-2">
        <label for="add-note-{{ $actionIndex }}-priority" class="form-label small mb-1">Prioridad</label>
        <select id="add-note-{{ $actionIndex }}-priority"
            class="form-select form-select-sm" wire:model="priority">
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="media">Media</option>
            <option value="error">Error</option>
        </select>
    </div>
    <div class="col-md-6">
        <label for="add-note-{{ $actionIndex }}-owner" class="form-label small mb-1">Owner</label>
        <select id="add-note-{{ $actionIndex }}-owner"
            class="form-select form-select-sm" wire:model="owner_id">
            <option value="">— Sin owner —</option>
            @foreach ($this->visibleUsers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2" aria-hidden="true"></i>
        </button>
    </div>
</div>
