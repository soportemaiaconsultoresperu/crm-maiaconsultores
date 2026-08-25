{{--
    Shared opportunity create/edit form (RF-OPP-001).

    Expected data: $opportunity (null on create), $leads, $customers,
    $contacts (of scoped customers, with customer loaded), $stages (open),
    $currencies, $sources, $owners.

    Subject: exactly one of lead/customer (validated server-side; a small
    script hides the other select for convenience). Contact options are
    grouped by customer and filtered client-side; the server still verifies
    ownership of the contact.
--}}
@php
    $isEdit = $opportunity !== null;
    $priorities = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'];
    $defaultOwner = old('owner_id', $opportunity?->owner_id ?? auth()->id());
@endphp

<form method="POST"
      action="{{ $isEdit ? route('opportunities.update', $opportunity) : route('opportunities.store') }}"
      data-testid="opportunity-form"
      data-swal-loading>
    @csrf
    @if ($isEdit) @method('PUT') @endif

    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">{{ $isEdit ? 'Editar oportunidad '.$opportunity->code : 'Nueva oportunidad' }}</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <x-text-input name="title" label="Título" :value="$opportunity?->title" :required="true" help="Ej.: Consultoría de procesos — fase 1."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="estimated_amount" type="number" label="Monto estimado" :value="$opportunity?->estimated_amount" :required="true" step="0.01" min="0"/>
                </div>

                <div class="col-md-4">
                    <x-select name="lead_id" label="Lead (opcional si indica cliente)" :value="$opportunity?->lead_id"
                              :placeholder="''" :required="false" data-subject-select="lead">
                        <option value="">— Sin lead —</option>
                        @foreach ($leads as $lead)
                            @php($leadLabel = trim(($lead->first_name.' '.$lead->last_name)).($lead->company_name ? ' — '.$lead->company_name : '').' ('.$lead->code.')')
                            <option value="{{ $lead->id }}" {{ (string) old('lead_id', $opportunity?->lead_id) === (string) $lead->id ? 'selected' : '' }}>
                                {{ $leadLabel }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <div class="col-md-4">
                    <x-select name="customer_id" label="Cliente (opcional si indica lead)" :value="$opportunity?->customer_id"
                              :placeholder="''" :required="false" data-subject-select="customer">
                        <option value="">— Sin cliente —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" {{ (string) old('customer_id', $opportunity?->customer_id) === (string) $customer->id ? 'selected' : '' }}>
                                {{ $customer->legal_name }} ({{ $customer->code }})
                            </option>
                        @endforeach
                    </x-select>
                    <div class="form-text">Indique exactamente uno: lead o cliente.</div>
                </div>
                <div class="col-md-4">
                    <select name="contact_id" id="contact_id" class="form-select @error('contact_id') is-invalid @enderror"
                            aria-label="Contacto" data-testid="contact-select">
                        <option value="">Contacto (opcional)</option>
                        @foreach ($contacts->groupBy('customer.legal_name') as $customerName => $customerContacts)
                            <optgroup label="{{ $customerName }}" data-customer-group>
                                @foreach ($customerContacts as $contact)
                                    <option value="{{ $contact->id }}" data-customer-id="{{ $contact->customer_id }}"
                                            {{ (string) old('contact_id', $opportunity?->contact_id) === (string) $contact->id ? 'selected' : '' }}>
                                        {{ trim($contact->first_name.' '.$contact->last_name) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-validation-error name="contact_id"/>
                    <div class="form-text">Solo se listan los contactos de los clientes visibles.</div>
                </div>

                @unless ($isEdit)
                    <div class="col-md-4">
                        <x-select name="stage_id" label="Etapa inicial" :options="$stages->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()"
                                  :value="old('stage_id', $stages->first()?->id)" :required="true" help="Por defecto: primera etapa abierta."/>
                    </div>
                @endunless

                <div class="col-md-4">
                    <x-select name="currency_code" label="Moneda"
                              :options="$currencies->mapWithKeys(fn ($c) => [$c->code => $c->code.' — '.$c->name])->all()"
                              :value="old('currency_code', $opportunity?->currency_code ?? 'PEN')" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="probability" type="number" label="Probabilidad (%)" :value="$opportunity?->probability" min="0" max="100" step="1"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="expected_close_at" type="date" label="Cierre estimado" :value="$opportunity?->expected_close_at?->format('Y-m-d')"/>
                </div>

                <div class="col-md-4">
                    <x-select name="owner_id" label="Responsable" :options="$owners->mapWithKeys(fn ($o) => [$o->id => $o->name])->all()"
                              :value="$defaultOwner" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-select name="source_id" label="Origen" :options="$sources->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()"
                              :value="$opportunity?->source_id" :placeholder="'Seleccione'"/>
                </div>
                <div class="col-md-4">
                    <x-select name="priority" label="Prioridad" :options="$priorities" :value="old('priority', $opportunity?->priority ?? 'media')"/>
                </div>

                <div class="col-12">
                    <label for="description" class="form-label mb-1">Descripción</label>
                    <textarea name="description" id="description" rows="3" class="form-control @error('description') is-invalid @enderror"
                              placeholder="Detalles de la oportunidad">{{ old('description', $opportunity?->description) }}</textarea>
                    <x-validation-error name="description"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-testid="btn-save-opportunity">
                {{ $isEdit ? 'Guardar cambios' : 'Crear oportunidad' }}
            </button>
            <a href="{{ $isEdit ? route('opportunities.show', $opportunity) : route('opportunities.index') }}"
               class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>

@push('scripts')
    <script>
        (function () {
            var form = document.querySelector('[data-testid="opportunity-form"]');
            if (!form) {
                return;
            }

            var leadSelect = form.querySelector('select[name="lead_id"]');
            var customerSelect = form.querySelector('select[name="customer_id"]');
            var contactSelect = form.querySelector('select[name="contact_id"]');

            // Only one subject: choosing one clears the other (server keeps
            // enforcing the exactly-one invariant).
            leadSelect.addEventListener('change', function () {
                if (leadSelect.value) {
                    customerSelect.value = '';
                }
                filterContacts();
            });

            customerSelect.addEventListener('change', function () {
                if (customerSelect.value) {
                    leadSelect.value = '';
                }
                filterContacts();
            });

            function filterContacts() {
                if (!contactSelect) {
                    return;
                }

                var customerId = customerSelect.value;

                contactSelect.querySelectorAll('option[data-customer-id]').forEach(function (option) {
                    option.hidden = customerId !== '' && option.dataset.customerId !== customerId;
                });

                var selected = contactSelect.querySelector('option[selected]');
                if (customerId !== '' && selected && selected.dataset.customerId !== customerId) {
                    contactSelect.value = '';
                }
            }
        })();
    </script>
@endpush
