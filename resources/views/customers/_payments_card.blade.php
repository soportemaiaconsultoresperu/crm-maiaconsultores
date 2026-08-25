@if ($canViewPayments ?? false)
    @php
        $invoices = $customer->relationLoaded('invoices') ? $customer->invoices : collect();
        $statuses = $invoiceStatuses ?? collect();
        $money = fn ($amount) => number_format((float) $amount, 2);
    @endphp

    <div class="card mt-3" data-testid="customer-payments-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Pagos</h3>
            @if ($canManagePayments ?? false)
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#invoice-create-modal">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva factura
                </button>
            @endif
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <div>
                    <div class="small text-secondary">Modalidad de pago</div>
                    <div class="fw-medium">{{ $customer->payment_modality ?: 'Modalidad pendiente' }}</div>
                </div>
                @if ($canManagePayments ?? false)
                    <form method="POST" action="{{ route('customers.payment-modality.update', $customer) }}" class="d-flex gap-2 align-items-end">
                        @csrf
                        <div>
                            <label for="payment_modality" class="form-label small mb-1">Actualizar modalidad</label>
                            <input type="text" name="payment_modality" id="payment_modality" value="{{ old('payment_modality', $customer->payment_modality) }}" class="form-control form-control-sm" maxlength="100" placeholder="Ej. Transferencia">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar modalidad</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($invoices->isEmpty())
            <div class="px-3 pb-3">
                @include('layouts.partials.empty-state', [
                    'message' => 'Sin facturas registradas.',
                    'hint' => ($canManagePayments ?? false) ? 'Cree la primera factura manual para este cliente.' : 'No hay facturas disponibles para consultar.',
                ])
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" data-testid="customer-payments-invoices-table">
                    <thead class="table-light">
                        <tr>
                            <th>Número</th>
                            <th>Vencimiento</th>
                            <th class="text-end">Importe total</th>
                            <th>Estado</th>
                            <th>Notas</th>
                            @if ($canManagePayments ?? false)
                                <th class="text-end">Acciones</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr data-testid="customer-payment-invoice-row">
                                <td>{{ $invoice->invoice_number }}</td>
                                <td class="text-nowrap">{{ $invoice->due_date?->format('d/m/Y') }}</td>
                                <td class="text-end">{{ $money($invoice->total_amount) }}</td>
                                <td>{{ $invoice->status?->name ?? '—' }}</td>
                                <td>{{ $invoice->notes ? \Illuminate\Support\Str::limit($invoice->notes, 80) : '—' }}</td>
                                @if ($canManagePayments ?? false)
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#invoice-edit-modal-{{ $invoice->id }}">Editar</button>
                                        <form method="POST" action="{{ route('customer-invoices.mark-paid', $invoice) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success">Marcar pagada</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#invoice-retire-modal-{{ $invoice->id }}">Retirar</button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($canManagePayments ?? false)
        <x-modal id="invoice-create-modal" title="Nueva factura">
            @include('customers._invoice_form', ['customer' => $customer, 'invoice' => null, 'invoiceStatuses' => $statuses])
        </x-modal>

        @foreach ($invoices as $invoice)
            <x-modal id="invoice-edit-modal-{{ $invoice->id }}" title="Editar factura {{ $invoice->invoice_number }}">
                @include('customers._invoice_form', ['customer' => $customer, 'invoice' => $invoice, 'invoiceStatuses' => $statuses])
            </x-modal>

            <x-modal id="invoice-retire-modal-{{ $invoice->id }}" title="Retirar factura {{ $invoice->invoice_number }}">
                <form method="POST" action="{{ route('customer-invoices.retire', $invoice) }}" data-swal-loading>
                    @csrf
                    <div class="mb-3">
                        <label for="retire-reason-{{ $invoice->id }}" class="form-label">Motivo</label>
                        <input type="text" name="reason" id="retire-reason-{{ $invoice->id }}" class="form-control" maxlength="255" required>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Retirar</button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @endif
@endif
