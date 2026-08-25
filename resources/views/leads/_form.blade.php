{{--
    Shared lead create/edit form (RF-LEAD-001).

    Expected data: $lead (null on create), $statuses, $sources, $owners,
    $departamentos, $provincias, $distritos. The duplicate warning block
    (session 'duplicates') renders above the form; when active, the
    confirmation button submits confirmed_duplicate=1 (ADR-003).
--}}
@php
    $ubigeoCode = old('ubigeo_code', $lead?->ubigeo_code ?? null);
    $deptCode = $ubigeoCode !== null ? substr($ubigeoCode, 0, 2).'0000' : null;
    $provCode = $ubigeoCode !== null ? substr($ubigeoCode, 0, 4).'00' : null;

    $personTypes = ['natural' => 'Persona natural', 'juridica' => 'Persona jurídica'];
    $docTypes = ['dni' => 'DNI', 'ruc' => 'RUC', 'ce' => 'Carné de extranjería', 'pasaporte' => 'Pasaporte', 'otro' => 'Otro'];
    $interestLevels = ['bajo' => 'Bajo', 'medio' => 'Medio', 'alto' => 'Alto'];
@endphp

@if (session('duplicates'))
    @include('leads.partials.duplicate-warning')
@endif

<form method="POST"
      action="{{ $lead === null ? route('leads.store') : route('leads.update', $lead) }}"
      class="lead-form"
      data-swal-loading>
    @if ($lead !== null) @method('PUT') @endif
    @csrf

    <div class="card lead-form-hero mb-3">
        <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <p class="text-uppercase text-secondary small mb-1">Prospectos</p>
                <h2 class="h4 mb-1">{{ $lead === null ? 'Registrar nuevo prospecto' : 'Actualizar prospecto '.$lead->code }}</h2>
                <p class="text-secondary mb-0">Capturá datos comerciales, contacto, ubicación y seguimiento en un formulario ordenado.</p>
            </div>
            <span class="dashboard-kpi-icon text-bg-primary" aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title lead-section-title mb-0"><i class="bi bi-person-lines-fill" aria-hidden="true"></i> Datos del prospecto</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <x-select name="person_type" label="Tipo de persona" :options="$personTypes" :value="$lead?->person_type ?? 'natural'" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-select name="doc_type" label="Tipo de documento" :options="$docTypes" :value="$lead?->doc_type" placeholder="Seleccione"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="doc_number" label="Número de documento" :value="$lead?->doc_number" help="DNI: 8 dígitos, RUC: 11 dígitos."/>
                </div>

{{-- first_name and last_name are hidden for juridica prospects (company_name covers it). --}}
                <div class="col-md-4" id="first_name_field" data-show-when="natural">
                    <x-text-input name="first_name" label="Nombres" :value="$lead?->first_name"/>
                </div>
                <div class="col-md-4" id="last_name_field" data-show-when="natural">
                    <x-text-input name="last_name" label="Apellidos" :value="$lead?->last_name"/>
                </div>
                <div class="col-md-4" id="company_name_field" data-show-when="juridica">
                    <x-text-input name="company_name" label="Empresa" :value="$lead?->company_name"/>
                </div>
                <div class="col-md-4" id="legal_name_field" data-show-when="juridica">
                    <x-text-input name="legal_name" label="Razón social" :value="$lead?->legal_name"/>
                </div>
                <div class="col-md-4" id="trade_name_field" data-show-when="juridica">
                    <x-text-input name="trade_name" label="Nombre comercial" :value="$lead?->trade_name"/>
                </div>

                <div class="col-md-4">
                    <x-text-input name="position" label="Cargo" :value="$lead?->position"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="phone" label="Teléfono" :value="$lead?->phone"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="whatsapp" label="WhatsApp" :value="$lead?->whatsapp"/>
                </div>

<div class="col-md-4">
                    <x-text-input name="email" type="email" label="Correo electrónico" :value="$lead?->email"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="website" label="Sitio web" :value="$lead?->website" placeholder="https://..."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="sector" label="Sector / rubro" :value="$lead?->sector"/>
                </div>
                <div class="col-md-12">
                    <x-text-input name="address" label="Dirección" :value="$lead?->address"/>
                </div>

                <div class="col-md-4">
                    <x-label for="ubigeo_departamento" label="Departamento"/>
                    <select name="ubigeo_departamento" id="ubigeo_departamento" class="form-select" data-testid="ubigeo-departamento">
                        <option value="">Seleccione</option>
                        @foreach ($departamentos as $departamento)
                            <option value="{{ $departamento->code }}" @if ((string) old('ubigeo_departamento', $deptCode) === $departamento->code) selected @endif>
                                {{ $departamento->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <x-label for="ubigeo_provincia" label="Provincia"/>
                    <select name="ubigeo_provincia" id="ubigeo_provincia" class="form-select" data-testid="ubigeo-provincia">
                        <option value="">Seleccione</option>
                        @foreach (($provincias ?? collect()) as $provincia)
                            <option value="{{ $provincia->code }}" @if ((string) old('ubigeo_provincia', $provCode) === $provincia->code) selected @endif>
                                {{ $provincia->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <x-label for="ubigeo_code" label="Distrito"/>
                    <select name="ubigeo_code" id="ubigeo_code" class="form-select" data-testid="ubigeo-distrito">
                        <option value="">Seleccione</option>
                        @foreach (($distritos ?? collect()) as $distrito)
                            <option value="{{ $distrito->code }}" @if ((string) old('ubigeo_code') === $distrito->code) selected @endif>
                                {{ $distrito->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-validation-error name="ubigeo_code"/>
                </div>

                <div class="col-md-4">
                    <x-select name="source_id" label="Origen"
                             :options="$sources->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()"
                             :value="$lead?->source_id" placeholder="Seleccione" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-select name="status_id" label="Estado"
                             :options="$statuses->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()"
                             :value="$lead?->status_id" placeholder="Seleccione" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-select name="interest_level" label="Nivel de interés" :options="$interestLevels" :value="$lead?->interest_level" placeholder="Seleccione"/>
                </div>

                <div class="col-md-4">
                    <x-select name="owner_id" label="Responsable"
                             :options="$owners->mapWithKeys(fn ($o) => [$o->id => $o->name])->all()"
                             :value="$lead?->owner_id ?? auth()->id()" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="entered_at" type="date" label="Fecha de ingreso" :value="$lead?->entered_at?->format('Y-m-d')"/>
                </div>
                <div class="col-md-12">
                    <x-label for="observations" label="Observaciones"/>
                    <textarea name="observations" id="observations" rows="3" class="form-control @error('observations') is-invalid @enderror">{{ old('observations', $lead?->observations ?? '') }}</textarea>
                    <x-validation-error name="observations"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                {{ $lead === null ? 'Crear prospecto' : 'Guardar cambios' }}
            </button>
            <a href="{{ $lead === null ? route('leads.index') : route('leads.show', $lead) }}" class="btn btn-outline-secondary">Cancelar</a>

            @if (session('duplicates'))
                <button type="submit" name="confirmed_duplicate" value="1"
                        class="btn btn-danger ms-auto" data-testid="confirm-duplicate">
                    Confirmar y {{ $lead === null ? 'crear' : 'guardar' }} de todos modos
                </button>
            @endif
        </div>
    </div>
</form>

@php($ubigeoUrl = route('leads.ubigeo', ['parent' => 'PARENTCODE']))

@once
    @push('scripts')
        <script>
                (function () {
                    'use strict';

                    // person_type-driven field visibility: juridica hides first_name
                    // and last_name (company_name covers them), natural hides them when
                    // the operator chooses the opposite path. The `required` attribute is
                    // preserved across toggles via a `data-was-required` marker so the
                    // server-side validation matches the visible fields.
                    var personType = document.querySelector('select[name="person_type"]');
                    var conditionalFields = document.querySelectorAll('[data-show-when]');

                    function syncPersonType() {
                        if (personType === null) {
                            return;
                        }
                        var value = personType.value;
                        conditionalFields.forEach(function (field) {
                            var showWhen = field.getAttribute('data-show-when');
                            var input = field.querySelector('input, select, textarea');
                            var visible = (showWhen === value);
                            field.style.display = visible ? '' : 'none';
                            if (input !== null) {
                                if (visible) {
                                    if (input.hasAttribute('data-was-required')) {
                                        input.setAttribute('required', '');
                                        input.removeAttribute('data-was-required');
                                    }
                                } else {
                                    if (input.hasAttribute('required')) {
                                        input.setAttribute('data-was-required', '');
                                        input.removeAttribute('required');
                                    }
                                    input.value = '';
                                }
                            }
                        });
                    }
                    if (personType !== null) {
                        personType.addEventListener('change', syncPersonType);
                        syncPersonType();
                    }

                    var base = '{!! $ubigeoUrl !!}';
                var departamento = document.getElementById('ubigeo_departamento');
                var provincia = document.getElementById('ubigeo_provincia');
                var distrito = document.getElementById('ubigeo_code');

                if (departamento === null) {
                    return;
                }

                function url(code) {
                    return base.replace('PARENTCODE', code);
                }

                function fill(select, children, selected) {
                    select.innerHTML = '<option value="">Seleccione</option>';

                    children.forEach(function (child) {
                        var option = document.createElement('option');
                        option.value = child.code;
                        option.textContent = child.name;

                        if (selected !== undefined && String(selected) === String(child.code)) {
                            option.selected = true;
                        }

                        select.appendChild(option);
                    });
                }

                function load(select, parentCode, selected) {
                    if (!parentCode) {
                        fill(select, [], undefined);
                        return Promise.resolve();
                    }

                    return fetch(url(parentCode), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (children) { fill(select, children, selected); });
                }

                departamento.addEventListener('change', function () {
                    provincia.innerHTML = '<option value="">Seleccione</option>';
                    distrito.innerHTML = '<option value="">Seleccione</option>';
                    load(provincia, departamento.value);
                });

                provincia.addEventListener('change', function () {
                    distrito.innerHTML = '<option value="">Seleccione</option>';
                    load(distrito, provincia.value);
                });

                // No-JS-safe preselection: when the selects were rendered with a
                // value already selected, nothing is fetched. When a departamento
                // has no provincias rendered yet (create re-entry), fetch them.
                if (departamento.value && provincia.options.length <= 1) {
                    load(provincia, departamento.value, '{{ $provCode }}').then(function () {
                        if (provincia.value && distrito.options.length <= 1) {
                            load(distrito, provincia.value, '{{ $ubigeoCode }}');
                        }
                    });
                } else if (provincia.value && distrito.options.length <= 1) {
                    load(distrito, provincia.value, '{{ $ubigeoCode }}');
                }
            })();
        </script>
    @endpush
@endonce
