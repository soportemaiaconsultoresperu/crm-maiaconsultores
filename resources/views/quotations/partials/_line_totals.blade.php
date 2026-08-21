{{--
    Header totals block for the quotation create/edit form (RF-COT-003).
    Server-side recalculation is the source of truth; the values here are
    computed live by the small JS so the user gets immediate feedback.
--}}
@php
    $currency = $currencyCode ?? ($quotation?->currency_code ?? 'PEN');
@endphp
<div class="d-flex flex-column align-items-end" data-line-totals data-currency="{{ $currency }}" data-testid="quotation-totals">
    <div class="d-flex gap-3 small">
        <span class="text-secondary">Subtotal:</span>
        <span class="fw-medium" data-line-totals-subtotal>0.00</span>
    </div>
    <div class="d-flex gap-3 small">
        <span class="text-secondary">Descuento:</span>
        <span class="fw-medium" data-line-totals-discount>0.00</span>
    </div>
    <div class="d-flex gap-3 small">
        <span class="text-secondary">Impuesto:</span>
        <span class="fw-medium" data-line-totals-tax>0.00</span>
    </div>
    <div class="d-flex gap-3 mt-1">
        <span class="fw-medium">Total {{ $currency }}:</span>
        <span class="fs-5 fw-bold text-primary" data-line-totals-total>0.00</span>
    </div>
</div>