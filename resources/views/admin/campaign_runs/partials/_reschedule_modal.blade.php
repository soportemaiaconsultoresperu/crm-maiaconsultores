<x-modal id="reschedule-modal" title="Reprogramación global de la ejecución">
    <form method="POST" action="{{ route('admin.campaign_runs.reschedule-all', $run) }}" data-swal-loading>
        @csrf

        <div class="mb-3">
            <x-label for="new_starts_at" label="Nueva fecha de inicio" :required="true"/>
            <input type="datetime-local" name="new_starts_at" id="new_starts_at" class="form-control @error('new_starts_at') is-invalid @enderror" required>
            <x-validation-error name="new_starts_at"/>
        </div>

        <div class="mb-3">
            <x-label for="reason" label="Motivo" :required="true"/>
            <textarea name="reason" id="reason" rows="3" class="form-control @error('reason') is-invalid @enderror" minlength="10" required></textarea>
            <x-validation-error name="reason"/>
            <small class="form-text text-secondary">Mínimo 10 caracteres. Quedará registrado en el historial.</small>
        </div>

        <x-alert type="warning">
            Solo se reprograman items en estado <strong>pendiente</strong> o <strong>vencido</strong>. Items completados, cancelados o "No aplica" NO se modifican.
        </x-alert>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-calendar-event me-1"></i> Reprogramar
            </button>
        </div>
    </form>
</x-modal>
