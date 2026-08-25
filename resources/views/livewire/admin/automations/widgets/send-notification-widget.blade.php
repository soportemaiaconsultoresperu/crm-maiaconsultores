<div class="row g-2">
    <div class="col-md-4">
        <label for="send-notification-{{ $actionIndex }}-user" class="form-label small mb-1">Usuario</label>
        <select id="send-notification-{{ $actionIndex }}-user"
            name="actions[{{ $actionIndex }}][payload_json][user_id]"
            class="form-select form-select-sm" wire:model="user_id">
            <option value="">— Responsable del registro —</option>
            @foreach ($this->visibleUsers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label for="send-notification-{{ $actionIndex }}-level" class="form-label small mb-1">Nivel</label>
        <select id="send-notification-{{ $actionIndex }}-level"
            name="actions[{{ $actionIndex }}][payload_json][level]"
            class="form-select form-select-sm" wire:model="level">
            <option value="info">Informativa</option>
            <option value="warning">Alerta</option>
            <option value="error">Crítica</option>
        </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de notificación">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
    <div class="col-md-6">
        <label for="send-notification-{{ $actionIndex }}-title" class="form-label small mb-1">Título</label>
        <input type="text" id="send-notification-{{ $actionIndex }}-title"
            name="actions[{{ $actionIndex }}][payload_json][title]"
            class="form-control form-control-sm" wire:model="title">
    </div>
    <div class="col-12">
        <label for="send-notification-{{ $actionIndex }}-body" class="form-label small mb-1">Mensaje de la notificación</label>
        <textarea id="send-notification-{{ $actionIndex }}-body" rows="3"
            name="actions[{{ $actionIndex }}][payload_json][body]"
            class="form-control form-control-sm" wire:model="body"></textarea>
    </div>
</div>
