{{--
    Reusable quotation line item row (RF-COT-001). Used in the create /
    edit forms. `$index` is the array position; `$item` is the array payload
    (product_id, description, quantity, unit, unit_price, discount_amount,
    tax_id). The wrapping <tr> is part of the partial so this can be cloned
    by the small add-row script.

    The totals per line and the header are recalculated client-side via
    tiny JS (read inputs, write outputs). Server-side recalculation is
    the single source of truth — see QuotationService::calculateTotals.
--}}
@php
    $productsOptions = ($products ?? collect())->mapWithKeys(fn ($p) => [
        $p->id => $p->code.' — '.$p->name.' ('.$p->currency_code.' '.number_format((float) $p->price, 2).')',
    ])->all();
    $taxesOptions = ($taxes ?? collect())->mapWithKeys(fn ($t) => [
        $t->id => $t->name.' ('.number_format((float) $t->rate, 2).'%)',
    ])->all();
    $defaultTaxId = $taxes->firstWhere('slug', 'gravado-igv')?->id ?? $taxes->first()?->id;
    $taxId = $item['tax_id'] ?? null;
@endphp
<tr data-line-form data-index="{{ $index }}" data-testid="quotation-line-row">
    <td class="text-center align-middle text-secondary" data-line-index>{{ (int) $index + 1 }}</td>
    <td>
        <select name="items[{{ $index }}][product_id]" class="form-select form-select-sm" data-line-field="product_id" aria-label="Producto">
            <option value="">— Manual —</option>
            @foreach (($products ?? collect()) as $product)
                <option value="{{ $product->id }}" @if ((string) ($item['product_id'] ?? null) === (string) $product->id) selected @endif>
                    {{ $product->code }} — {{ $product->name }}
                </option>
            @endforeach
        </select>
    </td>
    <td>
        <input type="text" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" maxlength="255"
               class="form-control form-control-sm @error('items.'.$index.'.description') is-invalid @enderror"
               required data-line-field="description" aria-label="Descripción">
        @error('items.'.$index.'.description')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>
    <td>
        <input type="text" name="items[{{ $index }}][unit]" value="{{ $item['unit'] ?? 'unidad' }}" maxlength="30"
               class="form-control form-control-sm" data-line-field="unit" aria-label="Unidad">
    </td>
    <td>
        <input type="number" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? '1' }}" min="0.01" step="0.01"
               class="form-control form-control-sm" required data-line-field="quantity" data-line-output="qty" aria-label="Cantidad">
    </td>
    <td>
        <input type="number" name="items[{{ $index }}][unit_price]" value="{{ $item['unit_price'] ?? '0.00' }}" min="0" step="0.01"
               class="form-control form-control-sm" required data-line-field="unit_price" data-line-output="price" aria-label="Precio unitario">
    </td>
    <td>
        <input type="number" name="items[{{ $index }}][discount_amount]" value="{{ $item['discount_amount'] ?? '0.00' }}" min="0" step="0.01"
               class="form-control form-control-sm" data-line-field="discount" data-line-output="discount" aria-label="Descuento">
    </td>
    <td>
        <select name="items[{{ $index }}][tax_id]" class="form-select form-select-sm" data-line-field="tax_id" aria-label="Impuesto">
            <option value="">Sin impuesto</option>
            @foreach (($taxes ?? collect()) as $tax)
                <option value="{{ $tax->id }}" data-tax-rate="{{ number_format((float) $tax->rate, 2, '.', '') }}"
                        @if ((string) ($taxId ?? $defaultTaxId) === (string) $tax->id) selected @endif>
                    {{ $tax->name }} ({{ number_format((float) $tax->rate, 2) }}%)
                </option>
            @endforeach
        </select>
    </td>
    <td class="text-end align-middle" data-line-total>0.00</td>
    <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger" data-line-remove aria-label="Quitar línea">
            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>
        Quitar</button>
    </td>
</tr>