{{--
    Lead → customer conversion form (RF-LEAD-013, ADR-001). Prefilled from
    the lead (customer fields + optional first contact); posts to
    leads.convert.store. Expected data: $lead, $prefill.
--}}
@extends('layouts.app')

@section('title', 'Convertir '.$lead->code.' a cliente')
@section('page-title', 'Convertir a cliente')

@section('content')
    <x-alert type="info">
        <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>
        El prospecto <strong>{{ $lead->code }}</strong> pasará al estado <em>convertido</em> y se creará un nuevo
        cliente con código CLI. El prospecto se conserva y su historial se integra en la ficha del cliente
        (ADR-001). La operación es una sola transacción: si algo falla, no queda estado parcial.
    </x-alert>

    <form method="POST" action="{{ route('leads.convert.store', $lead) }}" data-testid="convert-form">
        @csrf

        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0">Datos del cliente</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <x-select name="person_type" label="Tipo de persona"
                                 :options="['natural' => 'Persona natural', 'juridica' => 'Persona jurídica']"
                                 :value="old('person_type', $prefill['person_type'])" :required="true"/>
                    </div>
                    <div class="col-md-5">
                        <x-text-input name="legal_name" label="Razón social / Nombre completo"
                                     :value="old('legal_name', $prefill['legal_name'])" :required="true"/>
                    </div>
                    <div class="col-md-3">
                        <x-text-input name="trade_name" label="Nombre comercial" :value="old('trade_name', $prefill['trade_name'])"/>
                    </div>

                    <div class="col-md-3">
                        <x-select name="doc_type" label="Tipo de documento"
                                 :options="['dni' => 'DNI', 'ruc' => 'RUC', 'ce' => 'Carné de extranjería', 'pasaporte' => 'Pasaporte', 'otro' => 'Otro']"
                                 :value="old('doc_type', $prefill['doc_type'])" placeholder="Seleccione"/>
                    </div>
                    <div class="col-md-3">
                        <x-text-input name="doc_number" label="Número de documento"
                                     :value="old('doc_number', $prefill['doc_number'])" help="DNI: 8 dígitos, RUC: 11 dígitos."/>
                    </div>
                    <div class="col-md-3">
                        <x-text-input name="phone" label="Teléfono" :value="old('phone', $prefill['phone'])"/>
                    </div>
                    <div class="col-md-3">
                        <x-text-input name="whatsapp" label="WhatsApp" :value="old('whatsapp', $prefill['whatsapp'])"/>
                    </div>

                    <div class="col-md-4">
                        <x-text-input name="email" type="email" label="Correo electrónico" :value="old('email', $prefill['email'])"/>
                    </div>
                    <div class="col-md-8">
                        <x-text-input name="fiscal_address" label="Dirección fiscal" :value="old('fiscal_address', $prefill['fiscal_address'])"/>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title mb-0">Contacto inicial <span class="fw-normal text-secondary">(opcional)</span></h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">
                    Si completa al menos nombres y apellidos, se creará como contacto principal del cliente.
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <x-text-input name="contact[first_name]" label="Nombres"
                                     :value="old('contact.first_name', $prefill['contact_first_name'])"/>
                        <x-validation-error name="contact.first_name"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="contact[last_name]" label="Apellidos"
                                     :value="old('contact.last_name', $prefill['contact_last_name'])"/>
                        <x-validation-error name="contact.last_name"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="contact[position]" label="Cargo"
                                     :value="old('contact.position', $prefill['contact_position'])"/>
                        <x-validation-error name="contact.position"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="contact[phone]" label="Teléfono"
                                     :value="old('contact.phone', $prefill['contact_phone'])"/>
                        <x-validation-error name="contact.phone"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="contact[whatsapp]" label="WhatsApp"
                                     :value="old('contact.whatsapp', $prefill['contact_whatsapp'])"/>
                        <x-validation-error name="contact.whatsapp"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="contact[email]" type="email" label="Correo electrónico"
                                     :value="old('contact.email', $prefill['contact_email'])"/>
                        <x-validation-error name="contact.email"/>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-success" data-testid="btn-convert-submit">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Convertir a cliente
                </button>
                <a href="{{ route('leads.show', $lead) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
