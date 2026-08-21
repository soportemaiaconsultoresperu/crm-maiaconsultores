{{--
    Contact fields shared by the create modal and the edit modal
    (RF-CON-001). Expected data: $customer (route context), $contact
    (null on create). Field names are plain (not nested): each modal
    posts to its own endpoint.
--}}
@php
    $target = $contact === null
        ? route('customers.contacts.store', $customer)
        : route('contacts.update', $contact);
@endphp

<form method="POST" action="{{ $target }}" data-swal-loading>
    @csrf

    <div class="row g-3">
        <div class="col-md-6">
            <x-text-input name="first_name" label="Nombres" :value="$contact?->first_name" :required="true"/>
        </div>
        <div class="col-md-6">
            <x-text-input name="last_name" label="Apellidos" :value="$contact?->last_name" :required="true"/>
        </div>
        <div class="col-md-6">
            <x-text-input name="position" label="Cargo" :value="$contact?->position"/>
        </div>
        <div class="col-md-6">
            <x-text-input name="area" label="Área" :value="$contact?->area"/>
        </div>
        <div class="col-md-4">
            <x-text-input name="phone" label="Teléfono" :value="$contact?->phone"/>
        </div>
        <div class="col-md-4">
            <x-text-input name="whatsapp" label="WhatsApp" :value="$contact?->whatsapp"/>
        </div>
        <div class="col-md-4">
            <x-text-input name="email" type="email" label="Correo electrónico" :value="$contact?->email"/>
        </div>
        <div class="col-12">
            <x-label for="observations" label="Observaciones"/>
            <textarea name="observations" id="observations" rows="2" class="form-control @error('observations') is-invalid @enderror">{{ old('observations', $contact?->observations ?? '') }}</textarea>
            <x-validation-error name="observations"/>
        </div>
        @if ($contact === null)
            <div class="col-12 form-check">
                <input type="checkbox" name="is_primary" id="is_primary" value="1" class="form-check-input" @checked(old('is_primary'))>
                <label class="form-check-label" for="is_primary">
                    Establecer como contacto principal
                    <span class="d-block small text-secondary">Si ya existe un principal activo, se reasignará (RF-CON-002).</span>
                </label>
            </div>
        @endif
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">
            {{ $contact === null ? 'Agregar contacto' : 'Guardar cambios' }}
        </button>
    </div>
</form>
