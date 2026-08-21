<div class="row g-2">
    <div class="col-md-4">
        <label for="send-email-{{ $actionIndex }}-to" class="form-label small mb-1">Destinatario</label>
        <input type="email" id="send-email-{{ $actionIndex }}-to"
            class="form-control form-control-sm @error('to') is-invalid @enderror"
            wire:model="to">
        <x-validation-error name="to" />
    </div>
    <div class="col-md-4">
        <label for="send-email-{{ $actionIndex }}-subject" class="form-label small mb-1">Asunto</label>
        <input type="text" id="send-email-{{ $actionIndex }}-subject"
            class="form-control form-control-sm" wire:model="subject">
    </div>
    <div class="col-md-2">
        <label for="send-email-{{ $actionIndex }}-queue" class="form-label small mb-1">Cola</label>
        <div class="form-check form-switch mt-2">
            <input type="checkbox" id="send-email-{{ $actionIndex }}-queue"
                class="form-check-input" wire:model.boolean="queue">
            <label for="send-email-{{ $actionIndex }}-queue" class="form-check-label small">queue</label>
        </div>
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2" aria-hidden="true"></i>
        </button>
    </div>
    <div class="col-12">
        <label for="send-email-{{ $actionIndex }}-body" class="form-label small mb-1">Cuerpo (texto o Markdown)</label>
        <textarea id="send-email-{{ $actionIndex }}-body" rows="4"
            class="form-control form-control-sm" wire:model="body"></textarea>
    </div>
</div>
