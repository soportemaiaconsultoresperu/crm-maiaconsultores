{{--
    Shared activity create/edit form (RF-ACT-001..006).

    Expected data:
      $activity      (null on create, model on edit)
      $types         (active ActivityType rows)
      $owners        (selectable users in the actor's scope)
      $leads         (subject candidates)
      $customers     (subject candidates)
      $opportunities (subject candidates)
      $statuses, $priorities, $subjectTypes

    Subject selector: a 3-tab segmented control keeps the right (lead|customer|
    opportunity) sub-select visible; the server still validates the pair so the
    client script is purely UX.
--}}
@php
    $isEdit = $activity !== null;
    $storedSubjectType = $activity?->subject_type
        ? \App\Models\Activity::subjectKey($activity->subject_type)
        : 'lead';
    $defaultSubjectType = old('subject_type', $storedSubjectType);
    $defaultSubjectId = old('subject_id', $activity?->subject_id);
    $defaultStatus = old('status', $activity?->status ?? 'pending');
    $defaultPriority = old('priority', $activity?->priority ?? 'media');
    $defaultOwner = old('owner_id', $activity?->owner_id ?? auth()->id());
    $defaultScheduledAt = old('scheduled_at', $activity?->scheduled_at?->format('Y-m-d\TH:i'));
    $defaultReminderAt = old('reminder_at', $activity?->reminder_at?->format('Y-m-d\TH:i'));
@endphp

<form method="POST"
      action="{{ $isEdit ? route('activities.update', $activity) : route('activities.store') }}"
      data-testid="activity-form"
      data-swal-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">{{ $isEdit ? 'Editar actividad' : 'Nueva actividad' }}</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-12">
                    <x-text-input name="title" label="Título" :value="$activity?->title" :required="true" help="Ej.: Llamada de seguimiento / Reunión de cierre."/>
                </div>

                <div class="col-md-4">
                    <x-select name="type_id" label="Tipo de actividad"
                              :options="$types->mapWithKeys(fn ($t) => [$t->id => $t->name])->all()"
                              :value="$activity?->type_id" :required="true" placeholder="Seleccione"/>
                </div>
                <div class="col-md-4">
                    <x-select name="status" label="Estado"
                              :options="$statuses"
                              :value="$defaultStatus" :required="true"/>
                </div>
                <div class="col-md-4">
                    <x-select name="priority" label="Prioridad"
                              :options="$priorities"
                              :value="$defaultPriority" :required="true" placeholder="Seleccione"/>
                </div>

                <div class="col-md-12">
                    <x-label for="subject_type" label="Sujeto" :required="true"/>
                    <div class="btn-group w-100" role="group" aria-label="Tipo de sujeto" data-testid="subject-type-group">
                        @foreach ($subjectTypes as $value => $label)
                            <input type="radio" class="btn-check" name="subject_type_radio" id="subject_type_{{ $value }}" value="{{ $value }}"
                                   @checked($defaultSubjectType === $value)>
                            <label class="btn btn-outline-primary" for="subject_type_{{ $value }}">{{ $label }}</label>
                        @endforeach
                    </div>
                    <input type="hidden" name="subject_type" id="subject_type_input" value="{{ $defaultSubjectType }}" data-testid="subject-type">
                    <x-validation-error name="subject_type"/>
                </div>

                <div class="col-md-12" data-subject-pane="lead" @if ($defaultSubjectType !== 'lead') hidden @endif>
                        <x-select name="subject_id_lead" label="Prospecto" :required="$defaultSubjectType === 'lead'"
                                  data-subject-select="lead" placeholder="Seleccione un prospecto" :disabled="$defaultSubjectType !== 'lead'">

                        @foreach ($leads as $lead)
                            <option value="{{ $lead->id }}" @selected((int) $defaultSubjectId === (int) $lead->id && $defaultSubjectType === 'lead')>
                                {{ \App\Models\Activity::subjectDisplayLabel($lead) }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <div class="col-md-12" data-subject-pane="customer" @if ($defaultSubjectType !== 'customer') hidden @endif>
                        <x-select name="subject_id_customer" label="Cliente" :required="$defaultSubjectType === 'customer'"
                                  data-subject-select="customer" placeholder="Seleccione un cliente" :disabled="$defaultSubjectType !== 'customer'">

                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) $defaultSubjectId === (int) $customer->id && $defaultSubjectType === 'customer')>
                                {{ \App\Models\Activity::subjectDisplayLabel($customer) }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <div class="col-md-12" data-subject-pane="opportunity" @if ($defaultSubjectType !== 'opportunity') hidden @endif>
                        <x-select name="subject_id_opportunity" label="Oportunidad" :required="$defaultSubjectType === 'opportunity'"
                                  data-subject-select="opportunity" placeholder="Seleccione una oportunidad" :disabled="$defaultSubjectType !== 'opportunity'">

                        @foreach ($opportunities as $opportunity)
                            <option value="{{ $opportunity->id }}" @selected((int) $defaultSubjectId === (int) $opportunity->id && $defaultSubjectType === 'opportunity')>
                                {{ \App\Models\Activity::subjectDisplayLabel($opportunity) }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <input type="hidden" name="subject_id" id="subject_id_input" value="{{ $defaultSubjectId ?? '' }}" data-testid="subject-id">
                <x-validation-error name="subject_id"/>

                <div class="col-md-4">
                    <x-text-input name="scheduled_at" type="datetime-local" label="Fecha programada" :value="$defaultScheduledAt" :required="true"
                                      help="Se sincroniza con Google Calendar usando la zona horaria del responsable o del sistema."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="reminder_at" type="datetime-local" label="Recordatorio (opcional)" :value="$defaultReminderAt"/>
                </div>
                <div class="col-md-4">
                    <x-select name="owner_id" label="Responsable"
                              :options="$owners->mapWithKeys(fn ($o) => [$o->id => $o->name])->all()"
                              :value="$defaultOwner" :required="true"/>
                </div>

                <div class="col-md-12">
                    <x-label for="description" label="Descripción (opcional)"/>
                    <textarea name="description" id="description" rows="3" class="form-control @error('description') is-invalid @enderror">{{ old('description', $activity?->description) }}</textarea>
                    <x-validation-error name="description"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-testid="btn-submit">
                {{ $isEdit ? 'Guardar cambios' : 'Crear actividad' }}
            </button>
            <a href="{{ $isEdit ? route('activities.show', $activity) : route('activities.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>

@once
    @push('scripts')
        <script>
            (function () {
                'use strict';

                var typeRadios = document.querySelectorAll('input[name="subject_type_radio"]');
                var typeInput = document.getElementById('subject_type_input');
                var idInput = document.getElementById('subject_id_input');

                if (typeInput === null || idInput === null) {
                    return;
                }

                function syncActiveSubject(key) {
                    typeInput.value = key;

                    var activeSelect = null;
                    document.querySelectorAll('[data-subject-pane]').forEach(function (pane) {
                        var isActive = pane.getAttribute('data-subject-pane') === key;
                        var select = pane.querySelector('select[data-subject-select]');

                        pane.hidden = ! isActive;
                        if (select !== null) {
                            select.disabled = ! isActive;
                            select.required = isActive;

                            if (isActive) {
                                activeSelect = select;
                            }
                        }
                    });

                    idInput.value = activeSelect !== null ? activeSelect.value : '';
                }

                typeRadios.forEach(function (radio) {
                    if (radio.checked) {
                        typeInput.value = radio.value;
                    }

                    radio.addEventListener('change', function () {
                        if (radio.checked) {
                            syncActiveSubject(radio.value);
                        }
                    });
                });

                document.querySelectorAll('select[data-subject-select]').forEach(function (select) {
                    select.addEventListener('change', function () {
                        if (! select.disabled && select.getAttribute('data-subject-select') === typeInput.value) {
                            idInput.value = select.value;
                        }
                    });
                });

                syncActiveSubject(typeInput.value);
            })();
        </script>
    @endpush
@endonce
