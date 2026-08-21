<div class="row g-2">
    <div class="col-md-4">
        <label for="change-stage-{{ $actionIndex }}-slug" class="form-label small mb-1">Pipeline stage</label>
        <select id="change-stage-{{ $actionIndex }}-slug"
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
        <label for="change-stage-{{ $actionIndex }}-note" class="form-label small mb-1">Nota</label>
        <textarea id="change-stage-{{ $actionIndex }}-note" rows="2"
            class="form-control form-control-sm"
            wire:model="note"></textarea>
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2" aria-hidden="true"></i>
        </button>
    </div>
</div>
