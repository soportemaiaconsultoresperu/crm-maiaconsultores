{{--
    Complete-with-next form (RF-ACT-005, ADR-012). The follow-up fields are
    required only when the "create_next" toggle is on; the toggle is a
    simple checkbox with a "on" value that the controller maps to
    `create_next`. Server validation handles the required_if case.
--}}
@php
    $defaultResult = old('result', '');
@endphp
<form method="POST" action="{{ route('activities.complete', $activity) }}" data-testid="complete-form" data-swal-loading>
    @csrf
    <div class="mb-3">
        <x-label for="result" label="Resultado" :required="true"/>
        <textarea name="result" id="result" rows="3" class="form-control @error('result') is-invalid @enderror" required>{{ $defaultResult }}</textarea>
        <div class="form-text">Describa el resultado o la conclusión de la actividad.</div>
        <x-validation-error name="result"/>
    </div>

    <div class="form-check form-switch mb-3">
        <input type="checkbox" name="create_next" value="on" id="create_next" class="form-check-input"
               {{ old('create_next') === 'on' ? 'checked' : '' }} data-testid="toggle-next">
        <label for="create_next" class="form-check-label">Crear siguiente actividad</label>
    </div>

    <div id="next-fields" @if (old('create_next') !== 'on') hidden @endif>
        <div class="row g-2">
            <div class="col-md-4">
                <x-select name="next_type_id" label="Tipo del siguiente seguimiento"
                          :options="\App\Models\ActivityType::query()->where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($t) => [$t->id => $t->name])->all()"
                          :value="old('next_type_id')" placeholder="Seleccione"/>
            </div>
            <div class="col-md-4">
                <x-text-input name="next_scheduled_at" type="datetime-local" label="Fecha del siguiente" :value="old('next_scheduled_at')"/>
            </div>
            <div class="col-md-4">
                <x-text-input name="next_title" label="Título del siguiente" :value="old('next_title')"/>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="submit" class="btn btn-success" data-testid="btn-confirm-complete">Confirmar completada</button>
    </div>
</form>

@once
    @push('scripts')
        <script>
            (function () {
                'use strict';

                var toggle = document.getElementById('create_next');
                var fields = document.getElementById('next-fields');

                if (toggle === null || fields === null) {
                    return;
                }

                function sync() {
                    fields.hidden = !toggle.checked;
                }

                toggle.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endpush
@endonce
