{{--
    B12-UI / PR 5 — Reusable idempotency_key copy component (HIST-06, UI-07).

    Renders the literal stored value of `AutomationExecution::idempotency_key`
    in a monospace `<code>` with a "Copiar" button that calls
    `navigator.clipboard.writeText(...)`.

    Usage:
        <x-idempotency-key-copy :value="$execution->idempotency_key" />

    Props:
        - value (string, required) — the literal SHA-1 hex (or any opaque id).
        - label (string, optional) — text shown next to the value (default '').

    The button has a 2-second `<span>` visual swap from "Copiar" to "Copiado"
    driven by a tiny inline `onclick` handler. No external JS dependency.

    Width-capped at `max-width: 480px; overflow-x: auto;` so long ids never
    overflow the parent card.
--}}
@props(['value' => '', 'label' => ''])

@php
    $safeValue = (string) $value;
    $safeLabel = (string) $label;
    $componentId = 'idk-' . substr(sha1($safeValue . '|' . $safeLabel), 0, 12);
@endphp

<div class="d-flex align-items-start gap-2" style="max-width: 480px;">
    @if ($safeLabel !== '')
        <span class="small text-muted text-nowrap pt-1">{{ $safeLabel }}</span>
    @endif

    <code id="{{ $componentId }}-value"
          class="user-select-all font-monospace flex-grow-1 p-2 bg-light border rounded text-break"
          style="max-width: 480px; overflow-x: auto; font-size: 0.8125rem;"
          data-testid="idempotency-key-value">{{ $safeValue }}</code>

    <button type="button"
            class="btn btn-sm btn-outline-secondary text-nowrap"
            style="min-width: 78px;"
            data-testid="idempotency-key-copy"
            onclick="
                (function() {
                    var el = document.getElementById('{{ $componentId }}-value');
                    if (!el) return;
                    var v = el.textContent || '';
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(v).then(function() {
                            var b = document.getElementById('{{ $componentId }}-btn');
                            if (!b) return;
                            var prev = b.textContent;
                            b.textContent = 'Copiado';
                            b.classList.add('btn-success');
                            b.classList.remove('btn-outline-secondary');
                            setTimeout(function() {
                                b.textContent = prev;
                                b.classList.remove('btn-success');
                                b.classList.add('btn-outline-secondary');
                            }, 2000);
                        });
                    }
                })();
            ">
        <span id="{{ $componentId }}-btn">Copiar</span>
    </button>
</div>
