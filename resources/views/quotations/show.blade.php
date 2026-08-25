@extends('layouts.app')

@section('title', 'Cotización '.$quotation->number)
@section('page-title', $quotation->number)

@section('content')
    @php
        $statusLabel = match ($quotation->status) {
            'draft' => 'Borrador',
            'sent' => 'Enviada',
            'accepted' => 'Aceptada',
            'rejected' => 'Rechazada',
            'expired' => 'Vencida',
            'voided' => 'Anulada',
            default => ucfirst($quotation->status),
        };
        $symbol = $quotation->currency_code;
        $subject = $quotation->customer
            ? $quotation->customer->legal_name
            : trim(($quotation->lead?->first_name.' '.($quotation->lead?->last_name ?? '')).($quotation->lead?->company_name ? ' — '.$quotation->lead->company_name : ''));
        $canSend = in_array($quotation->status, ['draft'], true);
        $canAccept = in_array($quotation->status, ['draft', 'sent'], true);
        $canReject = in_array($quotation->status, ['draft', 'sent'], true);
        $canVoid = ! in_array($quotation->status, ['voided'], true);
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('quotations.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al listado
        </a>

        @can('update', $quotation)
            @if ($quotation->status === 'draft')
                <a href="{{ route('quotations.edit', $quotation) }}" class="btn btn-primary" data-testid="btn-edit-quotation">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                </a>
            @endif
        @endcan

        <a href="{{ route('quotations.pdf', $quotation) }}" class="btn btn-outline-secondary" data-testid="btn-print-pdf">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i> Imprimir PDF
        </a>

            @can('update', $quotation)
            @if ($canSend)
                <a href="{{ route('quotations.gmail-confirm', $quotation) }}" class="btn btn-outline-primary" data-testid="btn-send-gmail">
                    <i class="bi bi-envelope-paper me-1" aria-hidden="true"></i> Enviar por Gmail
                </a>
                <form method="POST" action="{{ route('quotations.send', $quotation) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary" data-testid="btn-mark-sent-manual">
                        <i class="bi bi-send-check me-1" aria-hidden="true"></i> Marcar como enviada
                    </button>
                </form>
            @endif

            @if ($canAccept)
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#accept-modal" data-testid="btn-accept">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Aceptar
                </button>
            @endif

            @if ($canReject)
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject-modal" data-testid="btn-reject">
                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i> Rechazar
                </button>
            @endif
        @endcan

        @can('create', App\Models\Quotation::class)
            <form method="POST" action="{{ route('quotations.duplicate', $quotation) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-secondary" data-testid="btn-duplicate">
                    <i class="bi bi-files me-1" aria-hidden="true"></i> Duplicar
                </button>
            </form>
        @endcan

        @can('delete', $quotation)
            @if ($canVoid)
                <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#void-modal" data-testid="btn-void">
                    <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Anular
                </button>
            @endif
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Cabecera</h3>
                    <span data-testid="quotation-status"><x-badge-status :status="$quotation->status"/></span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Número</dt>
                        <dd class="col-sm-7" data-testid="quotation-number">{{ $quotation->number }}</dd>

                        <dt class="col-sm-5">Cliente / Lead</dt>
                        <dd class="col-sm-7">
                            @if ($quotation->customer)
                                <a href="{{ route('customers.show', $quotation->customer) }}">{{ $quotation->customer->legal_name }}</a>
                                <div class="small text-secondary">{{ $quotation->customer->code }}</div>
                            @elseif ($quotation->lead)
                                <a href="{{ route('leads.show', $quotation->lead) }}">{{ $subject }}</a>
                                <div class="small text-secondary">{{ $quotation->lead->code }}</div>
                            @endif
                        </dd>

                        @if ($quotation->opportunity)
                            <dt class="col-sm-5">Oportunidad</dt>
                            <dd class="col-sm-7">
                                <a href="{{ route('opportunities.show', $quotation->opportunity) }}">{{ $quotation->opportunity->code }}</a>
                                <div class="small text-secondary">{{ $quotation->opportunity->title }}</div>
                            </dd>
                        @endif

                        @if ($quotation->contact)
                            <dt class="col-sm-5">Contacto</dt>
                            <dd class="col-sm-7">{{ trim($quotation->contact->first_name.' '.$quotation->contact->last_name) }}</dd>
                        @endif

                        <dt class="col-sm-5">Responsable</dt>
                        <dd class="col-sm-7">{{ $quotation->owner?->name ?? '—' }}</dd>

                        <dt class="col-sm-5">Moneda</dt>
                        <dd class="col-sm-7">{{ $quotation->currency_code }}</dd>

                        <dt class="col-sm-5">Emisión</dt>
                        <dd class="col-sm-7">{{ $quotation->issued_at?->format('d/m/Y') }}</dd>

                        @if ($quotation->expires_at)
                            <dt class="col-sm-5">Válida hasta</dt>
                            <dd class="col-sm-7">{{ $quotation->expires_at->format('d/m/Y') }}</dd>
                        @endif

                        @if ($quotation->accepted_at)
                            <dt class="col-sm-5">Aceptada el</dt>
                            <dd class="col-sm-7">{{ $quotation->accepted_at->format('d/m/Y H:i') }}</dd>
                        @endif

                        @if ($quotation->terms)
                            <dt class="col-sm-5">Términos</dt>
                            <dd class="col-sm-7">{{ $quotation->terms }}</dd>
                        @endif

                        @if ($quotation->observations)
                            <dt class="col-sm-5">Observaciones</dt>
                            <dd class="col-sm-7">{{ $quotation->observations }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card mt-3" data-testid="quotation-totals-card">
                <div class="card-header"><h3 class="card-title mb-0">Totales</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6">Subtotal</dt>
                        <dd class="col-sm-6 text-end">{{ $quotation->currency_code }} {{ number_format((float) $quotation->subtotal, 2) }}</dd>

                        <dt class="col-sm-6">Descuento</dt>
                        <dd class="col-sm-6 text-end">{{ $quotation->currency_code }} {{ number_format((float) $quotation->discount_total, 2) }}</dd>

                        <dt class="col-sm-6">Impuesto</dt>
                        <dd class="col-sm-6 text-end">{{ $quotation->currency_code }} {{ number_format((float) $quotation->tax_total, 2) }}</dd>

                        <dt class="col-sm-6 fs-5">Total</dt>
                        <dd class="col-sm-6 text-end fs-5 fw-bold text-primary" data-testid="quotation-total">
                            {{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }}
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Ítems</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0" data-testid="quotation-items-table">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Descripción</th>
                                <th>Unidad</th>
                                <th class="text-end">Cant.</th>
                                <th class="text-end">Precio</th>
                                <th class="text-end">Descuento</th>
                                <th class="text-end">Impuesto</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($quotation->items as $item)
                                <tr>
                                    <td class="text-center text-secondary">{{ $loop->iteration }}</td>
                                    <td>
                                        {{ $item->description }}
                                        @if ($item->product)
                                            <div class="small text-secondary">{{ $item->product->code }} — {{ $item->product->name }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $item->unit }}</td>
                                    <td class="text-end">{{ number_format((float) $item->quantity, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $item->discount_amount, 2) }}</td>
                                    <td class="text-end">
                                        {{ $item->tax?->name ?? '—' }}
                                        @if ($item->tax)
                                            <div class="small text-secondary">{{ number_format((float) $item->tax_rate, 2) }}%</div>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format((float) $item->line_total, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8">
                                        @include('layouts.partials.empty-state', ['message' => 'Sin ítems registrados.'])
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-3" data-testid="quotation-timeline">
                <div class="card-header"><h3 class="card-title mb-0">Línea de tiempo</h3></div>
                <div class="card-body">
                    @forelse ($history as $item)
                        <div class="d-flex gap-3 pb-3 mb-3 border-bottom" data-testid="history-item">
                            <i class="bi {{ $item['kind'] === 'activity' ? 'bi-chat-left-text' : 'bi-clock-history' }} fs-5 text-secondary" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1 fw-medium">{{ $item['title'] }}</p>
                                @if ($item['detail'])
                                    <p class="mb-1 small">{{ $item['detail'] }}</p>
                                @endif
                                <p class="mb-0 small text-secondary">
                                    {{ $item['at']->format('d/m/Y H:i') }}
                                    @if ($item['kind'] === 'activity' && isset($item['meta']['type']))
                                        — {{ $item['meta']['type'] }}
                                        — <x-badge-status :status="$item['meta']['status'] ?? ''"/>
                                    @elseif ($item['kind'] === 'log' && ! empty($item['meta']['event']))
                                        — {{ $item['meta']['event'] }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        @include('layouts.partials.empty-state', ['message' => 'Sin actividades ni cambios registrados.'])
@endforelse
                </div>
            </div>

            @include('documents.partials._panel', [
                'subject' => $quotation,
                'documents' => $quotation->documents()->orderByDesc('uploaded_at')->orderByDesc('id')->get(),
            ])
        </div>
    </div>

    {{-- Modals for state transitions --}}

    @can('update', $quotation)
        @if ($canAccept)
            <x-modal id="accept-modal" title="Aceptar cotización">
                <form method="POST" action="{{ route('quotations.accept', $quotation) }}" data-testid="accept-form" data-swal-loading>
                    @csrf
                    @php
                        $oppOpen = $quotation->opportunity !== null && ($quotation->opportunity->stage?->stage_type === 'open');
                    @endphp
                    @if ($oppOpen)
                        <p class="text-secondary">
                                La cotización está vinculada a una oportunidad abierta. ¿Desea marcarla como ganada al aceptar?
                            </p>
                            <p class="small">
                                <strong>Monto final:</strong> {{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }} —
                                <strong>Fecha de cierre:</strong> {{ now()->format('d/m/Y') }}
                            </p>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirm_opportunity_won" name="confirm_opportunity_won" value="1" checked>
                                <label class="form-check-label" for="confirm_opportunity_won">
                                    Marcar la oportunidad {{ $quotation->opportunity?->code }} como ganada con estos valores.
                                </label>
                            </div>
                            <p class="small text-secondary">
                                Desmarque si solo desea aceptar la cotización sin cerrar la oportunidad (RF-COT-008).
                            </p>
                        @else
                            <p class="text-secondary">
                                La cotización se marcará como aceptada.
                                @if ($quotation->opportunity)
                                    La oportunidad vinculada ya está cerrada; no se modificará.
                                @else
                                    No hay oportunidad vinculada.
                                @endif
                            </p>
                            <input type="hidden" name="confirm_opportunity_won" value="0">
                        @endif
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar aceptación</button>
                    </div>
                </form>
            </x-modal>
        @endif

        @if ($canReject)
            <x-swal-confirm
                :action="route('quotations.reject', $quotation)"
                method="POST"
                title="¿Rechazar cotización?"
                text="La cotización {{ $quotation->number }} quedará en estado rechazada. Se conserva el historial."
                type="warning"
                confirm-text="Sí, rechazar"
                input="textarea"
                input-name="reason"
                input-label="Motivo"
                input-required="true"
                input-placeholder="Indique el motivo del rechazo (RF-COT-004)…"
                button-class="btn-outline-danger">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i> Rechazar
            </x-swal-confirm>
        @endif
    @endcan

    @can('delete', $quotation)
        @if ($canVoid)
            <x-swal-confirm
                :action="route('quotations.destroy', $quotation)"
                method="DELETE"
                title="¿Anular cotización?"
                text="La cotización {{ $quotation->number }} se anulará. Esta acción no se puede revertir."
                type="error"
                confirm-text="Sí, anular"
                input="textarea"
                input-name="reason"
                input-label="Motivo"
                input-required="true"
                input-placeholder="Indique el motivo de la anulación…"
                button-class="btn-outline-danger ms-auto">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Anular
            </x-swal-confirm>
        @endif
    @endcan
@endsection