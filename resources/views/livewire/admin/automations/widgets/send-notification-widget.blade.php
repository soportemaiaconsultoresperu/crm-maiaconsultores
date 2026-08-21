<div class="row g-2">
    <div class="col-md-4">
        <label for="send-notification-{{ $actionIndex }}-user" class="form-label small mb-1">Usuario</label>
        <select id="send-notification-{{ $actionIndex }}-user"
            class="form-select form-select-sm" wire:model="user_id">
            <option value="">— Asunto owner —</option>
            @foreach ($this->visibleUsers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label for="send-notification-{{ $actionIndex }}-level" class="form-label small mb-1">Nivel</label>
        <select id="send-notification-{{ $actionIndex }}-level"
            class="form-select form-select-sm" wire:model="level">
            <option value="info">info</option>
            <option value="warning">warning</option>
            <option value="error">error</option>
        </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar
        </button>
    </div>
    <div class="col-md-6">
        <label for="send-notification-{{ $actionIndex }}-title" class="form-label small mb-1">Título</label>
        <input type="text" id="send-notification-{{ $actionIndex }}-title"
            class="form-control form-control-sm" wire:model="title">
    </div>
    <div class="col-12">
        <label for="send-notification-{{ $actionIndex }}-body" class="form-label small mb-1">Cuerpo</label>
        <textarea id="send-notification-{{ $actionIndex }}-body" rows="3"
            class="form-control form-control-sm" wire:model="body"></textarea>
    </div>
</div>
