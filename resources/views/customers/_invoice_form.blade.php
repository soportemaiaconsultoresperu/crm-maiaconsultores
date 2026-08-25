@php
    $isEdit = $invoice !== null;
@endphp

<form method="POST" action="{{ $isEdit ? route('customer-invoices.update', $invoice) : route('customers.invoices.store', $customer) }}" data-swal-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="row g-3">
        <div class="col-md-6">
            <label for="invoice_number_{{ $isEdit ? $invoice->id : 'new' }}" class="form-label">Número</label>
            <input type="text" name="invoice_number" id="invoice_number_{{ $isEdit ? $invoice->id : 'new' }}" value="{{ old('invoice_number', $invoice?->invoice_number) }}" class="form-control" maxlength="60" required>
        </div>
        <div class="col-md-6">
            <label for="due_date_{{ $isEdit ? $invoice->id : 'new' }}" class="form-label">Vencimiento</label>
            <input type="date" name="due_date" id="due_date_{{ $isEdit ? $invoice->id : 'new' }}" value="{{ old('due_date', $invoice?->due_date?->toDateString()) }}" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label for="total_amount_{{ $isEdit ? $invoice->id : 'new' }}" class="form-label">Importe total</label>
            <input type="number" name="total_amount" id="total_amount_{{ $isEdit ? $invoice->id : 'new' }}" value="{{ old('total_amount', $invoice?->total_amount) }}" class="form-control" min="0.01" step="0.01" required>
        </div>
        <div class="col-md-6">
            <label for="status_id_{{ $isEdit ? $invoice->id : 'new' }}" class="form-label">Estado</label>
            <select name="status_id" id="status_id_{{ $isEdit ? $invoice->id : 'new' }}" class="form-select" required>
                <option value="">Seleccione</option>
                @foreach ($invoiceStatuses as $status)
                    <option value="{{ $status->id }}" @selected((int) old('status_id', $invoice?->status_id) === $status->id)>{{ $status->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12">
            <label for="notes_{{ $isEdit ? $invoice->id : 'new' }}" class="form-label">Notas</label>
            <textarea name="notes" id="notes_{{ $isEdit ? $invoice->id : 'new' }}" rows="2" class="form-control" maxlength="2000">{{ old('notes', $invoice?->notes) }}</textarea>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar factura' : 'Crear factura' }}</button>
    </div>
</form>
