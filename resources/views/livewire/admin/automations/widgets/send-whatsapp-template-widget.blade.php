{{-- B12-UI — PR 4 / Stage 4 — send_whatsapp_template widget (B14 STUB). --}}
<div>
    <div class="alert alert-warning" role="alert">
        <strong>Acción no disponible para producción.</strong>
        <div class="small mt-1">La configuración se conserva en la regla, pero el envío real de WhatsApp queda pendiente de habilitación técnica. No se ejecutará en producción.</div>
    </div>

    <div class="row g-2" aria-disabled="true">
        <div class="col-md-4">
            <label for="send-whatsapp-{{ $actionIndex }}-template" class="form-label small mb-1">Plantilla de WhatsApp</label>
            <input type="text" id="send-whatsapp-{{ $actionIndex }}-template"
                class="form-control form-control-sm" wire:model="template_name" disabled>
        </div>
        <div class="col-md-4">
            <label for="send-whatsapp-{{ $actionIndex }}-phone" class="form-label small mb-1">Número de teléfono</label>
            <input type="tel" id="send-whatsapp-{{ $actionIndex }}-phone"
                class="form-control form-control-sm" wire:model="phone_number" disabled>
        </div>
        <div class="col-md-3">
            <label for="send-whatsapp-{{ $actionIndex }}-language" class="form-label small mb-1">Idioma de la plantilla</label>
            <input type="text" id="send-whatsapp-{{ $actionIndex }}-language"
                class="form-control form-control-sm" wire:model="language" disabled>
        </div>
        <div class="col-12">
            <label class="form-label small mb-1">Datos variables de la plantilla</label>
            @forelse ($variables as $i => $row)
                <div class="input-group input-group-sm mb-1" wire:key="wa-var-{{ $i }}">
                    <input type="text" class="form-control" placeholder="Nombre del dato"
                        value="{{ is_array($row) ? ($row['key'] ?? '') : '' }}" disabled>
                    <input type="text" class="form-control" placeholder="Valor del dato"
                        value="{{ is_array($row) ? ($row['value'] ?? '') : '' }}" disabled>
                </div>
            @empty
                <div class="small text-muted">Sin variables.</div>
            @endforelse
        </div>
        <div class="col-md-4">
            <label for="send-whatsapp-{{ $actionIndex }}-account" class="form-label small mb-1">Cuenta conectada</label>
            <input type="text" id="send-whatsapp-{{ $actionIndex }}-account"
                class="form-control form-control-sm" wire:model="account_id" disabled>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <span class="badge text-bg-secondary w-100 py-2" aria-label="WhatsApp no disponible para producción">
                No disponible
            </span>
        </div>
    </div>
</div>
