@extends('layouts.app')

@section('title', 'Aceptar cotización '.$quotation->number)
@section('page-title', 'Aceptar '.$quotation->number)

@section('content')
    @php
        $opp = $quotation->opportunity;
        $oppIsOpen = $opp !== null && ($opp->stage?->stage_type === 'open');
    @endphp

    <div class="row g-3">
        <div class="col-lg-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">¿Marcar oportunidad como ganada?</h3>
                </div>
                <div class="card-body">
                    @if ($oppIsOpen)
                        <p>
                            La cotización <strong>{{ $quotation->number }}</strong> está vinculada a la oportunidad
                            <a href="{{ route('opportunities.show', $opp) }}">{{ $opp->code }} — {{ $opp->title }}</a>.
                        </p>
                        <p>
                            Al aceptarla también puede marcar la oportunidad como ganada con los siguientes valores:
                        </p>
                        <ul class="list-unstyled small mb-3">
                            <li><strong>Monto final:</strong> {{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }}</li>
                            <li><strong>Fecha de cierre:</strong> {{ now()->format('d/m/Y') }}</li>
                        </ul>

                        <div class="d-flex flex-column gap-2">
                            <form method="POST" action="{{ route('quotations.accept', $quotation) }}" data-testid="accept-with-opp-form">
                                @csrf
                                <input type="hidden" name="confirm_opportunity_won" value="1">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-trophy me-1" aria-hidden="true"></i> Aceptar cotización y marcar oportunidad ganada
                                </button>
                            </form>

                            <form method="POST" action="{{ route('quotations.accept', $quotation) }}" data-testid="accept-only-form">
                                @csrf
                                <input type="hidden" name="confirm_opportunity_won" value="0">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-check2 me-1" aria-hidden="true"></i> Solo aceptar la cotización (sin cerrar la oportunidad)
                                </button>
                            </form>

                            <a href="{{ route('quotations.show', $quotation) }}" class="btn btn-outline-secondary w-100">Cancelar</a>
                        </div>
                    @else
                        <p class="text-secondary">
                            La oportunidad vinculada ya está cerrada o no existe; la cotización se aceptará sin tocar la oportunidad.
                        </p>
                        <form method="POST" action="{{ route('quotations.accept', $quotation) }}" data-testid="accept-no-opp-form">
                            @csrf
                            <input type="hidden" name="confirm_opportunity_won" value="0">
                            <button type="submit" class="btn btn-success">Aceptar cotización</button>
                            <a href="{{ route('quotations.show', $quotation) }}" class="btn btn-outline-secondary">Cancelar</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection