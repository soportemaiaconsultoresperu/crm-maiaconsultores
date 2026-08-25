<div class="row g-2">
    <div class="col-md-4">
        <label for="send-email-{{ $actionIndex }}-to" class="form-label small mb-1">Destinatario</label>
        <input type="email" id="send-email-{{ $actionIndex }}-to"
            name="actions[{{ $actionIndex }}][payload_json][to]"
            class="form-control form-control-sm @error('to') is-invalid @enderror"
            wire:model="to">
        <x-validation-error name="to" />
    </div>
    <div class="col-md-4">
        <label for="send-email-{{ $actionIndex }}-subject" class="form-label small mb-1">Asunto</label>
        <input type="text" id="send-email-{{ $actionIndex }}-subject"
            name="actions[{{ $actionIndex }}][payload_json][subject]"
            class="form-control form-control-sm" wire:model="subject">
    </div>
    <div class="col-md-2">
        <label for="send-email-{{ $actionIndex }}-queue" class="form-label small mb-1">Envío</label>
        <div class="form-check form-switch mt-2">
            <input type="hidden" name="actions[{{ $actionIndex }}][payload_json][queue]" value="0">
            <input type="checkbox" id="send-email-{{ $actionIndex }}-queue"
                name="actions[{{ $actionIndex }}][payload_json][queue]"
                value="1"
                class="form-check-input" wire:model.boolean="queue">
            <label for="send-email-{{ $actionIndex }}-queue" class="form-check-label small">Enviar en segundo plano</label>
        </div>
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit"
            wire:loading.attr="disabled"
            wire:target="emit"
            aria-label="Aplicar cambios de correo">
            <span wire:loading.remove wire:target="emit"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar</span>
            <span wire:loading wire:target="emit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Aplicando…</span>
        </button>
    </div>
    <div class="col-12">
        <label for="send-email-{{ $actionIndex }}-body" class="form-label small mb-1">Mensaje del correo</label>
        <textarea id="send-email-{{ $actionIndex }}-body" rows="4"
            name="actions[{{ $actionIndex }}][payload_json][body]"
            class="form-control form-control-sm" wire:model="body"></textarea>
    </div>
</div>
