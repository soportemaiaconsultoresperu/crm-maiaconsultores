{{--
    Shared customer create/edit form (RF-CLI-001). Expected data:
    $customer (null on create), $owners, $departamentos, $provincias,
    $distritos. The ubigeo dependent selects reuse the leads-ubigeo JSON
    endpoint (RF-CFG-003), same script as the lead form.
--}}
@php
    $ubigeoCode = old('ubigeo_code', $customer?->ubigeo_code ?? null);
    $deptCode = $ubigeoCode !== null ? substr($ubigeoCode, 0, 2).'0000' : null;
    $provCode = $ubigeoCode !== null ? substr($ubigeoCode, 0, 4).'00' : null;

    $personTypes = ['natural' => 'Persona natural', 'juridica' => 'Persona jurídica'];
    $docTypes = ['dni' => 'DNI', 'ruc' => 'RUC', 'ce' => 'Carné de extranjería', 'pasaporte' => 'Pasaporte', 'otro' => 'Otro'];
@endphp

    <form method="POST"
          action="{{ $customer === null ? route('customers.store') : route('customers.update', $customer) }}"
          data-swal-loading>
        @if ($customer !== null) @method('PUT') @endif
        @csrf

        @if ($errors->any())
            <x-alert type="danger">
                <strong>No se pudo guardar el cliente:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">Datos del cliente</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <x-select name="person_type" label="Tipo de persona" :options="$personTypes" :value="$customer?->person_type ?? 'juridica'" :required="true"/>
                </div>
<div class="col-md-4">
                    <x-text-input name="legal_name" label="Razón social / Nombre completo" :value="$customer?->legal_name" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="trade_name" label="Nombre comercial" :value="$customer?->trade_name"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="first_name" label="Nombres (contacto)" :value="$customer?->first_name"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="last_name" label="Apellidos (contacto)" :value="$customer?->last_name"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="position" label="Cargo (contacto)" :value="$customer?->position"/>
                </div>

                <div class="col-md-4">
                    <x-select name="doc_type" label="Tipo de documento" :options="$docTypes" :value="$customer?->doc_type" placeholder="Seleccione"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="doc_number" label="Número de documento" :value="$customer?->doc_number" help="DNI: 8 dígitos, RUC: 11 dígitos."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="sector" label="Sector" :value="$customer?->sector"/>
                </div>

                <div class="col-md-4">
                    <x-text-input name="phone" label="Teléfono" :value="$customer?->phone"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="whatsapp" label="WhatsApp" :value="$customer?->whatsapp"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="email" type="email" label="Correo electrónico" :value="$customer?->email"/>
                </div>

<div class="col-md-4">
                    <x-text-input name="website" label="Sitio web" :value="$customer?->website" placeholder="https://..."/>
                </div>
                <div class="col-md-8">
                    <x-text-input name="address" label="Dirección comercial" :value="$customer?->address"/>
                </div>
                <div class="col-md-8">
                    <x-text-input name="fiscal_address" label="Dirección fiscal" :value="$customer?->fiscal_address"/>
                </div>

<div class="col-md-4">
                        <x-label for="ubigeo_filter_departamento" label="Buscar departamento"/>
                        <input type="text" id="ubigeo_filter_departamento"
                               class="form-control form-control-sm mb-1"
                               placeholder="Escribí para filtrar (25 departamentos)…"
                               data-target="ubigeo_departamento">
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
                        <x-label for="ubigeo_filter_provincia" label="Buscar provincia"/>
                        <input type="text" id="ubigeo_filter_provincia"
                               class="form-control form-control-sm mb-1"
                               placeholder="Escribí para filtrar (196 provincias)…"
                               data-target="ubigeo_provincia">
                        <x-label for="ubigeo_provincia" label="Provincia"/>
                        <select name="ubigeo_provincia" id="ubigeo_provincia" class="form-select" data-testid="ubigeo-provincia">
                            <option value="">Seleccione</option>
                            @foreach ($provincias as $provincia)
                                <option value="{{ $provincia->code }}" @if ((string) old('ubigeo_provincia', $provCode) === $provincia->code) selected @endif>
                                    {{ $provincia->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <x-label for="ubigeo_filter_distrito" label="Buscar distrito"/>
                        <input type="text" id="ubigeo_filter_distrito"
                               class="form-control form-control-sm mb-1"
                               placeholder="Escribí para filtrar (1892 distritos)…"
                               data-target="ubigeo_code">
                        <x-label for="ubigeo_code" label="Distrito"/>
                        <select name="ubigeo_code" id="ubigeo_code" class="form-select" data-testid="ubigeo-distrito">
                            <option value="">Seleccione</option>
                            @foreach ($distritos as $distrito)
                                <option value="{{ $distrito->code }}" @if ((string) old('ubigeo_code', $ubigeoCode) === $distrito->code) selected @endif>
                                    {{ $distrito->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-validation-error name="ubigeo_code"/>
                    </div>

                <div class="col-md-4">
                    <x-select name="owner_id" label="Responsable"
                             :options="$owners->mapWithKeys(fn ($o) => [$o->id => $o->name])->all()"
                             :value="$customer?->owner_id ?? auth()->id()" :required="true"/>
                </div>
                @if ($customer !== null)
                    <div class="col-md-4">
                        <x-label for="status" label="Estado"/>
                        <select name="status" id="status" class="form-select">
                            @foreach (['activo' => 'Activo', 'inactivo' => 'Inactivo'] as $value => $label)
                                <option value="{{ $value }}" @if ((string) old('status', $customer->status) === $value) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-12">
                    <x-label for="observations" label="Observaciones"/>
                    <textarea name="observations" id="observations" rows="3" class="form-control @error('observations') is-invalid @enderror">{{ old('observations', $customer?->observations ?? '') }}</textarea>
                    <x-validation-error name="observations"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                {{ $customer === null ? 'Crear cliente' : 'Guardar cambios' }}
            </button>
            <a href="{{ $customer === null ? route('customers.index') : route('customers.show', $customer) }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>

@php($ubigeoUrl = route('leads.ubigeo', ['parent' => 'PARENTCODE']))

@once
    @push('scripts')
        <script>
            (function () {
                'use strict';

                // Ubigeo: ALL departamentos / provincias / distritos are
                // rendered server-side. Each <select> gets a sibling
                // <input data-target="…"> that filters options client-side
                // on every keystroke. The cascade is gone — only
                // ubigeo_code (distrito) is persisted, so the parent
                // selects are purely UX helpers. "Seleccione" is the
                // always-visible null option.
                function filterSelect(selectId, query) {
                    var select = document.getElementById(selectId);
                    if (select === null) {
                        return;
                    }
                    var q = (query || '').toLowerCase().trim();
                    Array.prototype.forEach.call(select.options, function (opt) {
                        if (opt.value === '') {
                            opt.hidden = false;
                            return;
                        }
                        opt.hidden = q !== '' && opt.textContent.toLowerCase().indexOf(q) === -1;
                    });
                }

                var bindings = [
                    { inputId: 'ubigeo_filter_departamento', selectId: 'ubigeo_departamento' },
                    { inputId: 'ubigeo_filter_provincia',    selectId: 'ubigeo_provincia'    },
                    { inputId: 'ubigeo_filter_distrito',     selectId: 'ubigeo_code'         },
                ];

                bindings.forEach(function (b) {
                    var input = document.getElementById(b.inputId);
                    if (input === null) {
                        return;
                    }
                    input.addEventListener('input', function () {
                        filterSelect(b.selectId, input.value);
                    });
                });
            })();
        </script>
    @endpush
@endonce
