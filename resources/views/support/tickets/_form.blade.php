@csrf

<div class="card">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <x-text-input name="title" label="Título" :value="old('title')" required />
            </div>
            <div class="col-md-4">
                <label class="form-label" for="priority_id">Prioridad <span class="text-danger">*</span></label>
                <select id="priority_id" name="priority_id" class="form-select @error('priority_id') is-invalid @enderror" required>
                    <option value="">Seleccione</option>
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority->id }}" @selected(old('priority_id') == $priority->id)>{{ $priority->name }}</option>
                    @endforeach
                </select>
                <x-validation-error name="priority_id" />
            </div>

            <div class="col-md-6">
                <label class="form-label" for="customer_id">Cliente <span class="text-danger">*</span></label>
                <select id="customer_id" name="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
                    <option value="">Seleccione</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->code }} — {{ $customer->trade_name ?: $customer->legal_name }}</option>
                    @endforeach
                </select>
                <x-validation-error name="customer_id" />
            </div>

            <div class="col-md-6">
                <label class="form-label" for="requester_contact_id">Contacto solicitante <span class="text-danger">*</span></label>
                <select id="requester_contact_id" name="requester_contact_id" class="form-select @error('requester_contact_id') is-invalid @enderror" required>
                    <option value="">Seleccione</option>
                    @foreach ($contacts as $contact)
                        <option value="{{ $contact->id }}" data-customer-id="{{ $contact->customer_id }}" @selected(old('requester_contact_id') == $contact->id)>{{ trim($contact->last_name.' '.$contact->first_name) }} — {{ $contact->email }}</option>
                    @endforeach
                </select>
                <x-validation-error name="requester_contact_id" />
            </div>

            <div class="col-md-4">
                <label class="form-label" for="type_id">Tipo <span class="text-danger">*</span></label>
                <select id="type_id" name="type_id" class="form-select @error('type_id') is-invalid @enderror" required>
                    <option value="">Seleccione</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected(old('type_id') == $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                <x-validation-error name="type_id" />
            </div>
            <div class="col-md-4">
                <label class="form-label" for="category_id">Categoría <span class="text-danger">*</span></label>
                <select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
                    <option value="">Seleccione</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-validation-error name="category_id" />
            </div>
            <div class="col-md-4">
                <label class="form-label" for="channel_id">Canal <span class="text-danger">*</span></label>
                <select id="channel_id" name="channel_id" class="form-select @error('channel_id') is-invalid @enderror" required>
                    <option value="">Seleccione</option>
                    @foreach ($channels as $channel)
                        <option value="{{ $channel->id }}" @selected(old('channel_id') == $channel->id)>{{ $channel->name }}</option>
                    @endforeach
                </select>
                <x-validation-error name="channel_id" />
            </div>

            <div class="col-12">
                <label class="form-label" for="description">Descripción <span class="text-danger">*</span></label>
                <textarea id="description" name="description" rows="5" class="form-control @error('description') is-invalid @enderror" required>{{ old('description') }}</textarea>
                <x-validation-error name="description" />
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="{{ route('support.tickets.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar ticket</button>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const customer = document.getElementById('customer_id');
        const requester = document.getElementById('requester_contact_id');

        if (!customer || !requester) {
            return;
        }

        const options = Array.from(requester.options);

        const filterContacts = () => {
            const customerId = customer.value;
            const current = requester.value;
            let currentIsAvailable = false;

            options.forEach((option) => {
                if (!option.value) {
                    option.hidden = false;
                    option.disabled = false;
                    option.textContent = customerId ? 'Seleccione' : 'Seleccione primero un cliente';
                    return;
                }

                const matches = option.dataset.customerId === customerId;
                option.hidden = !matches;
                option.disabled = !matches;
                if (matches && option.value === current) {
                    currentIsAvailable = true;
                }
            });

            requester.disabled = !customerId;
            if (!customerId || !currentIsAvailable) {
                requester.value = '';
            }
        };

        customer.addEventListener('change', filterContacts);
        filterContacts();
    });
</script>
@endpush
