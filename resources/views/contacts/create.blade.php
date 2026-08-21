@extends('layouts.app')

@section('title', 'Nuevo contacto')
@section('page-title', 'Nuevo contacto')

@section('content')
    <form method="POST" action="{{ route('contacts.store') }}">
        @csrf

        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0">Datos del contacto</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <x-label for="customer_id" label="Cliente" :required="true"/>
                        <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" data-testid="contact-customer-select" required>
                            <option value="">Seleccione</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @if ((string) old('customer_id') === (string) $customer->id) selected @endif>
                                    {{ $customer->legal_name }}
                                </option>
                            @endforeach
                        </select>
                        <x-validation-error name="customer_id"/>
                    </div>

                    <div class="col-md-6">
                        <x-text-input name="first_name" label="Nombre" :required="true"/>
                    </div>
                    <div class="col-md-6">
                        <x-text-input name="last_name" label="Apellido" :required="true"/>
                    </div>

                    <div class="col-md-6">
                        <x-text-input name="position" label="Cargo"/>
                    </div>
                    <div class="col-md-6">
                        <x-text-input name="area" label="Área"/>
                    </div>

                    <div class="col-md-4">
                        <x-text-input name="phone" label="Teléfono"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="whatsapp" label="WhatsApp"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="email" type="email" label="Email"/>
                    </div>

                    <div class="col-12 form-check">
                        <input type="checkbox" name="is_primary" id="is_primary" value="1" class="form-check-input" @checked(old('is_primary'))>
                        <label class="form-check-label" for="is_primary">
                            Contacto principal
                            <span class="d-block small text-secondary">Si ya existe un principal activo, se reasignará (RF-CON-002).</span>
                        </label>
                    </div>

                    <div class="col-md-12">
                        <x-label for="observations" label="Observaciones"/>
                        <textarea name="observations" id="observations" rows="3" class="form-control @error('observations') is-invalid @enderror">{{ old('observations') }}</textarea>
                        <x-validation-error name="observations"/>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary">Guardar</button>
                <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary">Volver</a>
            </div>
        </div>
    </form>
@endsection
