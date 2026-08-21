<x-modal id="product-edit-modal-{{ $customer->getKey() }}-{{ $product->id }}" title="Editar asociación: {{ $product->code }}">
    <form method="POST"
          action="{{ route('customers.products.update', [$customer, $product]) }}"
          data-testid="product-edit-form-{{ $product->id }}"
          data-swal-loading>
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-md-3">
                <x-text-input name="quantity"
                              type="number"
                              label="Cantidad"
                              :value="old('quantity', $product->pivot->quantity ?? 1)"
                              :required="true"
                              min="1"
                              max="99999"/>
            </div>
            <div class="col-md-4">
                <x-text-input name="price_override"
                              type="number"
                              step="0.01"
                              min="0"
                              :label="'Precio personalizado ('.$product->currency_code.')'"
                              :value="old('price_override', $product->pivot->price_override)"
                              help="Dejar vacío para usar el precio de catálogo."/>
            </div>
            <div class="col-md-5">
                <x-text-input name="notes"
                              label="Notas"
                              :value="old('notes', $product->pivot->notes)"
                              placeholder="Contratado en… / Interesado en…"/>
            </div>
            <div class="col-md-6">
                <x-text-input name="purchased_at"
                              type="date"
                              label="Fecha de contratación"
                              :value="old('purchased_at', $product->pivot->purchased_at)"/>
            </div>
            <div class="col-md-6">
                <x-text-input name="expires_at"
                              type="date"
                              label="Fecha de vencimiento"
                              :value="old('expires_at', $product->pivot->expires_at)"
                              help="Opcional. Debe ser igual o posterior a la fecha de contratación."/>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary" data-testid="btn-submit-product-edit-{{ $product->id }}">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar cambios
            </button>
        </div>
    </form>
</x-modal>
