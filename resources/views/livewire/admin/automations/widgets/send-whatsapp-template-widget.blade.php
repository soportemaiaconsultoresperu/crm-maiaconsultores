{{-- B12-UI — PR 4 / Stage 4 — send_whatsapp_template widget (B14 STUB). --}}
<div>
    <div class="alert alert-warning" role="alert">
        <strong>Pendiente (B14) — esta acción fallará con NotImplementedException hasta que se entregue B14.</strong>
        <div class="small mt-1">El form se activará en B14.</div>
    </div>

    <div class="row g-2" aria-disabled="true">
        <div class="col-md-4">
            <label for="send-whatsapp-{{ $actionIndex }}-template" class="form-label small mb-1">Template</label>
            <input type="text" id="send-whatsapp-{{ $actionIndex }}-template"
                class="form-control form-control-sm" wire:model="template_name" disabled>
        </div>
        <div class="col-md-4">
            <label for="send-whatsapp-{{ $actionIndex }}-phone" class="form-label small mb-1">Phone number</label>
            <input type="tel" id="send-whatsapp-{{ $actionIndex }}-phone"
                class="form-control form-control-sm" wire:model="phone_number" disabled>
        </div>
        <div class="col-md-3">
            <label for="send-whatsapp-{{ $actionIndex }}-language" class="form-label small mb-1">Language</label>
            <input type="text" id="send-whatsapp-{{ $actionIndex }}-language"
                class="form-control form-control-sm" wire:model="language" disabled>
        </div>
        <div class="col-12">
            <label class="form-label small mb-1">Variables (key=value)</label>
            @forelse ($variables as $i => $row)
                <div class="input-group input-group-sm mb-1" wire:key="wa-var-{{ $i }}">
                    <input type="text" class="form-control" placeholder="key"
                        value="{{ is_array($row) ? ($row['key'] ?? '') : '' }}" disabled>
                    <input type="text" class="form-control" placeholder="value"
                        value="{{ is_array($row) ? ($row['value'] ?? '') : '' }}" disabled>
                </div>
            @empty
                <div class="small text-muted">Sin variables.</div>
            @endforelse
        </div>
        <div class="col-md-4">
            <label for="send-whatsapp-{{ $actionIndex }}-account" class="form-label small mb-1">Account ID</label>
            <input type="text" id="send-whatsapp-{{ $actionIndex }}-account"
                class="form-control form-control-sm" wire:model="account_id" disabled>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                wire:click="emit" aria-label="Aplicar (B14 stub)">
                <i class="bi bi-check2" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</div>
