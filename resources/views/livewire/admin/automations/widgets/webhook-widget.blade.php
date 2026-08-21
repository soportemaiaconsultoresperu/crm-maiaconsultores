{{-- B12-UI — PR 4 / Stage 4 — webhook widget (B14 STUB). --}}
<div>
    <div class="alert alert-warning" role="alert">
        <strong>Pendiente (B14) — esta acción fallará con NotImplementedException hasta que se entregue B14.</strong>
        <div class="small mt-1">El form se activará en B14.</div>
    </div>

    @if ($this->allowedDestinations === [])
        <div class="alert alert-warning">
            Configure INTEGRATIONS_WEBHOOK_ALLOWED en su archivo .env para habilitar acciones webhook.
        </div>
    @else
        <div class="row g-2">
            <div class="col-md-6">
                <label for="webhook-{{ $actionIndex }}-url" class="form-label small mb-1">URL autorizada</label>
                <select id="webhook-{{ $actionIndex }}-url"
                    class="form-select form-select-sm"
                    wire:model="url" disabled>
                    <option value="">— Seleccionar —</option>
                    @foreach ($this->allowedDestinations as $dest)
                        <option value="{{ $dest }}">{{ $dest }}</option>
                    @endforeach
                </select>
                <div class="form-text">Lista leída de <code>integrations.webhooks.allowed_destinations</code>.</div>
            </div>
            <div class="col-md-3">
                <label for="webhook-{{ $actionIndex }}-method" class="form-label small mb-1">Método</label>
                <select id="webhook-{{ $actionIndex }}-method"
                    class="form-select form-select-sm" wire:model="method" disabled>
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                    <option value="PATCH">PATCH</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                    wire:click="emit" aria-label="Aplicar (B14 stub)">
                    <i class="bi bi-check2 me-1" aria-hidden="true"></i> Aplicar
                </button>
            </div>
            <div class="col-12">
                <label for="webhook-{{ $actionIndex }}-body" class="form-label small mb-1">Body</label>
                <textarea id="webhook-{{ $actionIndex }}-body" rows="3"
                    class="form-control form-control-sm font-monospace"
                    wire:model="body" disabled></textarea>
            </div>
        </div>
    @endif
</div>
