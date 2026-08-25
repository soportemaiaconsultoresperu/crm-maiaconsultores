{{-- B12-UI — PR 4 / Stage 4 — SimulateButton view.
     Renders the "Simular ahora" trigger and a modal that surfaces
     response_json (monospace) or the caught error envelope. --}}
<div wire:ignore.self>
    <button type="button" class="btn btn-sm btn-outline-secondary"
        wire:click="simulate"
        wire:loading.attr="disabled"
        wire:target="simulate"
        aria-label="Simular ahora">
        <span wire:loading.remove wire:target="simulate">
            <i class="bi bi-play-circle me-1" aria-hidden="true"></i> Simular ahora
        </span>
        <span wire:loading wire:target="simulate">
            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Simulando…
        </span>
    </button>

    @if ($isOpen)
        <div class="modal fade show d-block" tabindex="-1" role="dialog"
            style="background: rgba(0,0,0,0.5);">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Resultado — {{ $actionType }}</h5>
                        <button type="button" class="btn-close" wire:click="close" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        @if ($errorClass !== null || $errorMessage !== null)
                            <div class="alert alert-danger" role="alert">
                                <strong>{{ $errorClass ?? 'Error' }}:</strong>
                                {{ $errorMessage ?? '' }}
                            </div>
                        @endif
                        @if ($responseJson !== null)
                            <pre class="font-monospace small mb-0"><code>{{ json_encode($responseJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        @endif
                    </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                wire:click="close"
                                wire:loading.attr="disabled"
                                wire:target="close">Cerrar</button>
                        </div>
                </div>
            </div>
        </div>
    @endif
</div>
