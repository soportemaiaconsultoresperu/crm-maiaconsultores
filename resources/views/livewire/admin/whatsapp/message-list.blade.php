<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Mensajes</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    wire:click="loadMessages">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
                Refrescar
            </button>
        </div>

        <div class="card-body" style="max-height: 60vh; overflow-y: auto;">
            @forelse ($messages as $message)
                @php
                    $isOutbound = $message->direction === \App\Models\WhatsApp\WhatsAppMessage::DIRECTION_OUTBOUND;
                @endphp
                <div class="d-flex {{ $isOutbound ? 'justify-content-end' : 'justify-content-start' }} mb-2"
                     wire:key="msg-{{ $message->id }}">
                    <div class="p-2 px-3 rounded
                                {{ $isOutbound ? 'bg-success text-white' : 'bg-light border' }}"
                         style="max-width: 70%;">
                        <div class="small {{ $isOutbound ? 'text-white-50' : 'text-muted' }}">
                            {{ $message->direction === \App\Models\WhatsApp\WhatsAppMessage::DIRECTION_OUTBOUND ? 'Enviado' : 'Recibido' }}
                            @if ($message->template)
                                · <code class="{{ $isOutbound ? 'text-white-50' : '' }}">{{ $message->template->name }}</code>
                            @endif
                            · {{ $message->status }}
                        </div>
                        <div class="mt-1">{{ $message->body ?: '—' }}</div>
                    </div>
                </div>
            @empty
                <p class="text-muted text-center mb-0">
                    Esta conversación aún no tiene mensajes.
                </p>
            @endforelse
        </div>

        @if (method_exists($messages, 'links'))
            <div class="card-footer">{{ $messages->links() }}</div>
        @endif
    </div>

    @if ($canSend)
        <form wire:submit.prevent="send" class="card mt-3">
            <div class="card-header">
                <h3 class="card-title mb-0">Enviar mensaje</h3>
            </div>
            <div class="card-body">
                <textarea wire:model="body"
                          class="form-control @error('body') is-invalid @enderror"
                          rows="3"
                          placeholder="Escribe un mensaje libre…"
                          data-testid="message-body"></textarea>
                @error('body')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button type="submit"
                        class="btn btn-primary"
                        wire:loading.attr="disabled"
                        wire:target="send"
                        data-testid="message-send">
                    <span wire:loading.remove wire:target="send">
                        <i class="bi bi-send me-1" aria-hidden="true"></i> Enviar
                    </span>
                    <span wire:loading wire:target="send">
                        <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i> Enviando…
                    </span>
                </button>
            </div>
        </form>
    @elseif ($conversation?->opt_out_at !== null)
        <div class="alert alert-warning mt-3 mb-0">
            Esta conversación registró un opt-out; no se permiten más envíos.
        </div>
    @else
        <div class="alert alert-secondary mt-3 mb-0">
            Tu rol no permite enviar mensajes; solo lectura.
        </div>
    @endif
</div>