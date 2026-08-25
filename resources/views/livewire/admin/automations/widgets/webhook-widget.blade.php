{{-- B12-UI — PR 4 / Stage 4 — webhook widget (B14 STUB). --}}
<div>
    <div class="alert alert-warning" role="alert">
        <strong>Acción no disponible para producción.</strong>
        <div class="small mt-1">La configuración se conserva en la regla, pero el envío automático a otros sistemas queda pendiente de habilitación técnica. No se ejecutará en producción.</div>
    </div>

    @if ($this->allowedDestinations === [])
        <div class="alert alert-warning">
            Todavía no hay destinos autorizados para enviar datos a otros sistemas.
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
                <div class="form-text">Sólo se podrán usar destinos aprobados por administración.</div>
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
                <span class="badge text-bg-secondary w-100 py-2" aria-label="Webhook no disponible para producción">
                    No disponible
                </span>
            </div>
            <div class="col-12">
                <label for="webhook-{{ $actionIndex }}-body" class="form-label small mb-1">Datos a enviar</label>
                <textarea id="webhook-{{ $actionIndex }}-body" rows="3"
                    class="form-control form-control-sm font-monospace"
                    wire:model="body" disabled></textarea>
            </div>
        </div>
    @endif
</div>
