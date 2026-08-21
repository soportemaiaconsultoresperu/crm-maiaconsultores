{{--
    Shared quotation create/edit form (RF-COT-001). The form is one
    "tabla" (header info card + items table + totals footer). Items are
    managed client-side by a small JS that clones `_line_form` and
    re-runs the totals; server-side recalculation is the source of
    truth.

    Expected data:
      $quotation (null on create), $prefill (default subject values),
      $items (array of payload), $leads, $customers, $contacts,
      $opportunities, $currencies, $products, $taxes, $owners.
--}}
@php
    $isEdit = $quotation !== null;
    $prefill = $prefill ?? [];
    $items = $items ?? [];
    $lineIndexStart = 0;
    $lineCount = max(count($items), 1);
    $currencyCode = $prefill['currency_code'] ?? 'PEN';
    $statuses = [
        'draft' => 'Borrador',
        'sent' => 'Enviada',
        'accepted' => 'Aceptada',
        'rejected' => 'Rechazada',
        'expired' => 'Vencida',
        'voided' => 'Anulada',
    ];
@endphp

<form method="POST"
      action="{{ $isEdit ? route('quotations.update', $quotation) : route('quotations.store') }}"
      @if ($isEdit) @method('PUT') @endif
      data-testid="quotation-form"
      data-swal-loading>
    @csrf

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title mb-0">{{ $isEdit ? 'Editar cotización '.$quotation->number : 'Nueva cotización' }}</h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @if ($isEdit)
                    <div class="col-md-3">
                        <x-label for="number" label="Número"/>
                        <input type="text" id="number" class="form-control" value="{{ $quotation->number }}" disabled>
                    </div>
                @endif
                <div class="col-md-{{ $isEdit ? 3 : 4 }}">
                    <x-select name="lead_id" label="Lead (uno u otro)" :required="false"
                              :value="$prefill['lead_id'] ?? null"
                              data-subject-select="lead">
                        <option value="">— Sin lead —</option>
                        @foreach ($leads as $lead)
                            @php($leadLabel = trim(($lead->first_name.' '.($lead->last_name ?? ''))).($lead->company_name ? ' — '.$lead->company_name : '').' ('.$lead->code.')')
                            <option value="{{ $lead->id }}" {{ (string) old('lead_id', $prefill['lead_id'] ?? null) === (string) $lead->id ? 'selected' : '' }}>
                                {{ $leadLabel }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <div class="col-md-{{ $isEdit ? 3 : 4 }}">
                    <x-select name="customer_id" label="Cliente (uno u otro)" :required="false"
                              :value="$prefill['customer_id'] ?? null"
                              data-subject-select="customer">
                        <option value="">— Sin cliente —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" {{ (string) old('customer_id', $prefill['customer_id'] ?? null) === (string) $customer->id ? 'selected' : '' }}>
                                {{ $customer->legal_name }} ({{ $customer->code }})
                            </option>
                        @endforeach
                    </x-select>
                    <div class="form-text">Indique exactamente uno: lead o cliente (RF-COT-001).</div>
                </div>
                <div class="col-md-{{ $isEdit ? 3 : 4 }}">
                    <select name="contact_id" id="contact_id" class="form-select @error('contact_id') is-invalid @enderror"
                            aria-label="Contacto" data-testid="contact-select" data-subject-select="contact">
                        <option value="">— Sin contacto —</option>
                        @foreach ($contacts->groupBy('customer.legal_name') as $customerName => $customerContacts)
                            <optgroup label="{{ $customerName }}" data-customer-group>
                                @foreach ($customerContacts as $contact)
                                    <option value="{{ $contact->id }}" data-customer-id="{{ $contact->customer_id }}"
                                            data-lead-id="{{ $contact->lead_id ?? '' }}"
                                            {{ (string) old('contact_id', $prefill['contact_id'] ?? null) === (string) $contact->id ? 'selected' : '' }}>
                                        {{ trim($contact->first_name.' '.$contact->last_name) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-validation-error name="contact_id"/>
                </div>

                <div class="col-md-4">
                    <x-select name="opportunity_id" label="Oportunidad (opcional)" :required="false"
                              :value="$prefill['opportunity_id'] ?? null" data-subject-select="opportunity">
                        <option value="">— Sin oportunidad —</option>
                        @foreach ($opportunities as $opportunity)
                            <option value="{{ $opportunity->id }}" {{ (string) old('opportunity_id', $prefill['opportunity_id'] ?? null) === (string) $opportunity->id ? 'selected' : '' }}>
                                {{ $opportunity->code }} — {{ $opportunity->title }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <div class="col-md-4">
                    <x-text-input name="issued_at" type="date" label="Fecha de emisión"
                                  :value="$prefill['issued_at'] ?? ''" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="expires_at" type="date" label="Válida hasta"
                                  :value="$prefill['expires_at'] ?? ''"/>
                </div>

                <div class="col-md-3">
                    <x-select name="currency_code" label="Moneda" :required="true"
                              :options="$currencies->mapWithKeys(fn ($c) => [$c->code => $c->code.' — '.$c->name])->all()"
                              :value="$currencyCode"/>
                </div>
                <div class="col-md-3">
                    <x-select name="owner_id" label="Responsable" :required="true"
                              :options="$owners->mapWithKeys(fn ($o) => [$o->id => $o->name])->all()"
                              :value="$prefill['owner_id'] ?? auth()->id()"/>
                </div>
                @if ($isEdit)
                    <div class="col-md-3">
                        <x-label for="status" label="Estado"/>
                        <select name="status" id="status" class="form-select" disabled>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @if ($quotation->status === $value) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Las transiciones de estado se realizan desde la ficha.</div>
                    </div>
                @endif

                <div class="col-md-12">
                    <x-label for="terms" label="Términos y condiciones"/>
                    <textarea name="terms" id="terms" rows="2" class="form-control @error('terms') is-invalid @enderror"
                              placeholder="Ej.: Validez 15 días. Pago al contado.">{{ old('terms', $prefill['terms'] ?? '') }}</textarea>
                    <x-validation-error name="terms"/>
                </div>
                <div class="col-md-12">
                    <x-label for="observations" label="Observaciones"/>
                    <textarea name="observations" id="observations" rows="2" class="form-control @error('observations') is-invalid @enderror">{{ old('observations', $prefill['observations'] ?? '') }}</textarea>
                    <x-validation-error name="observations"/>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3" data-testid="quotation-items-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Ítems</h3>
            <button type="button" class="btn btn-sm btn-outline-primary" data-line-add>
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar línea
            </button>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover align-middle mb-0" data-testid="quotation-lines">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Producto</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Cant.</th>
                        <th>Precio</th>
                        <th>Descuento</th>
                        <th>Impuesto</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody data-line-rows>
                    @for ($i = 0; $i < $lineCount; $i++)
                        @include('quotations.partials._line_form', [
                            'index' => $i,
                            'item' => $items[$i] ?? [],
                            'products' => $products,
                            'taxes' => $taxes,
                        ])
                    @endfor
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            @include('quotations.partials._line_totals', ['currencyCode' => $currencyCode, 'quotation' => $quotation])
        </div>
    </div>

    @php($prefillJson = json_encode(['count' => $lineCount, 'currency' => $currencyCode]))

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary" data-testid="btn-save-quotation">
            {{ $isEdit ? 'Guardar cambios' : 'Crear cotización' }}
        </button>
        <a href="{{ $isEdit ? route('quotations.show', $quotation) : route('quotations.index') }}" class="btn btn-outline-secondary">Cancelar</a>
    </div>

    <template data-line-template>
        @include('quotations.partials._line_form', [
            'index' => '__INDEX__',
            'item' => [
                'product_id' => null,
                'description' => '',
                'quantity' => '1',
                'unit' => 'unidad',
                'unit_price' => '0.00',
                'discount_amount' => '0.00',
                'tax_id' => null,
            ],
            'products' => $products,
            'taxes' => $taxes,
        ])
    </template>

    @push('scripts')
        <script>
            (function () {
                'use strict';

                var form = document.querySelector('[data-testid="quotation-form"]');
                if (!form) {
                    return;
                }

                var tbody = form.querySelector('[data-line-rows]');
                var template = form.querySelector('[data-line-template]');
                var totalsBlock = form.querySelector('[data-line-totals]');
                var leadSelect = form.querySelector('select[name="lead_id"]');
                var customerSelect = form.querySelector('select[name="customer_id"]');
                var contactSelect = form.querySelector('select[name="contact_id"]');
                var opportunitySelect = form.querySelector('select[name="opportunity_id"]');
                var currencySelect = form.querySelector('select[name="currency_code"]');

                var money = function (n) {
                    if (!isFinite(n)) {
                        return '0.00';
                    }
                    return n.toFixed(2);
                };

                function taxRateFor(select) {
                    if (!select) {
                        return 0;
                    }
                    var opt = select.options[select.selectedIndex];
                    if (!opt || !opt.dataset.taxRate) {
                        return 0;
                    }
                    return parseFloat(opt.dataset.taxRate) || 0;
                }

                function recalcRow(row) {
                    var qty = parseFloat((row.querySelector('[data-line-field="quantity"]') || {}).value) || 0;
                    var price = parseFloat((row.querySelector('[data-line-field="unit_price"]') || {}).value) || 0;
                    var discount = parseFloat((row.querySelector('[data-line-field="discount"]') || {}).value) || 0;
                    var taxSelect = row.querySelector('[data-line-field="tax_id"]');
                    var rate = taxRateFor(taxSelect);

                    var subtotal = qty * price;
                    var taxable = Math.max(subtotal - discount, 0);
                    var tax = taxable * rate / 100;
                    var total = taxable + tax;

                    var totalCell = row.querySelector('[data-line-total]');
                    if (totalCell) {
                        totalCell.textContent = money(total);
                    }

                    return { subtotal: subtotal, discount: discount, tax: tax, total: total };
                }

                function recalcAll() {
                    var sub = 0, dis = 0, tax = 0;
                    var rows = tbody.querySelectorAll('[data-line-form]');
                    rows.forEach(function (row) {
                        var totals = recalcRow(row);
                        sub += totals.subtotal;
                        dis += totals.discount;
                        tax += totals.tax;
                    });
                    var grand = sub - dis + tax;

                    if (totalsBlock) {
                        var s = totalsBlock.querySelector('[data-line-totals-subtotal]');
                        var d = totalsBlock.querySelector('[data-line-totals-discount]');
                        var t = totalsBlock.querySelector('[data-line-totals-tax]');
                        var g = totalsBlock.querySelector('[data-line-totals-total]');
                        if (s) { s.textContent = money(sub); }
                        if (d) { d.textContent = money(dis); }
                        if (t) { t.textContent = money(tax); }
                        if (g) {
                            var c = totalsBlock.dataset.currency || 'PEN';
                            g.textContent = c + ' ' + money(grand);
                        }
                    }
                }

                function renumberRows() {
                    var rows = tbody.querySelectorAll('[data-line-form]');
                    rows.forEach(function (row, idx) {
                        row.dataset.index = idx;
                        var labelCell = row.querySelector('[data-line-index]');
                        if (labelCell) {
                            labelCell.textContent = (idx + 1);
                        }
                        row.querySelectorAll('[name^="items["]').forEach(function (input) {
                            input.name = input.name.replace(/items\[\d+\]/, 'items[' + idx + ']');
                        });
                    });
                    recalcAll();
                }

                function attachRowEvents(row) {
                    row.querySelectorAll('input[data-line-field], select[data-line-field]').forEach(function (input) {
                        input.addEventListener('input', recalcAll);
                        input.addEventListener('change', recalcAll);
                    });

                    var removeBtn = row.querySelector('[data-line-remove]');
                    if (removeBtn) {
                        removeBtn.addEventListener('click', function () {
                            var rows = tbody.querySelectorAll('[data-line-form]');
                            if (rows.length <= 1) {
                                // Clear values instead of removing the last row.
                                row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                                row.querySelectorAll('select').forEach(function (i) { i.selectedIndex = 0; });
                                recalcAll();
                                return;
                            }
                            row.parentNode.removeChild(row);
                            renumberRows();
                        });
                    }
                }

                tbody.querySelectorAll('[data-line-form]').forEach(attachRowEvents);

                var addBtn = form.querySelector('[data-line-add]');
                if (addBtn) {
                    addBtn.addEventListener('click', function () {
                        var html = template.innerHTML.replace(/__INDEX__/g, tbody.querySelectorAll('[data-line-form]').length);
                        var wrap = document.createElement('tbody');
                        wrap.innerHTML = html.trim();
                        var newRow = wrap.firstElementChild;
                        tbody.appendChild(newRow);
                        attachRowEvents(newRow);
                        renumberRows();
                    });
                }

                if (currencySelect) {
                    currencySelect.addEventListener('change', function () {
                        if (totalsBlock) {
                            totalsBlock.dataset.currency = currencySelect.value;
                            recalcAll();
                        }
                    });
                }

                if (leadSelect && customerSelect) {
                    leadSelect.addEventListener('change', function () {
                        if (leadSelect.value) {
                            customerSelect.value = '';
                            if (opportunitySelect) { opportunitySelect.value = ''; }
                        }
                        filterContacts();
                        recalcAll();
                    });
                    customerSelect.addEventListener('change', function () {
                        if (customerSelect.value) {
                            leadSelect.value = '';
                        }
                        filterContacts();
                        recalcAll();
                    });
                }

                function filterContacts() {
                    if (!contactSelect) {
                        return;
                    }
                    var customerId = customerSelect ? customerSelect.value : '';
                    contactSelect.querySelectorAll('option[data-customer-id]').forEach(function (option) {
                        var matchesCustomer = customerId !== '' && option.dataset.customerId === customerId;
                        var matchesLead = option.dataset.leadId && option.dataset.leadId !== '';
                        option.hidden = customerId !== '' && !matchesCustomer;
                        if (matchesLead) {
                            option.hidden = true;
                        }
                    });
                    var selected = contactSelect.querySelector('option:checked');
                    if (customerId !== '' && selected && selected.dataset.customerId !== customerId) {
                        contactSelect.value = '';
                    }
                }

                filterContacts();
                recalcAll();
            })();
        </script>
    @endpush
</form>