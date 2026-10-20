{{--
    Shared internal activity create/edit form.

    Expected data: $activity (null on create), $users, $roles, $defaultDate.
    The audience selector shows the roles or users picker that matches the
    chosen audience; the server validates the pair, the script is only UX.
--}}
@php
    $isEdit = $activity !== null;
    $defaultStart = $activity?->starts_at?->format('Y-m-d\TH:i')
        ?? ($defaultDate ? $defaultDate.'T09:00' : null);
    $audience = old('audience_type', $activity?->audience_type ?? 'all');
    $selectedRoles = array_map('intval', old('role_ids', $activity?->audienceRoles->pluck('id')->all() ?? []));
    $selectedUsers = array_map('intval', old('user_ids', $activity?->audienceUsers->pluck('id')->all() ?? []));
    $responsibleId = (int) old('responsible_id', $activity?->responsible()?->id);
    $selectedParticipants = array_map('intval', old('participant_ids', $activity?->participants
        ->reject(fn ($user) => (bool) $user->pivot->is_responsible)->pluck('id')->all() ?? []));
@endphp

<form method="POST"
      action="{{ $isEdit ? route('internal-activities.update', $activity) : route('internal-activities.store') }}"
      data-testid="internal-activity-form"
      data-swal-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">{{ $isEdit ? 'Editar actividad interna' : 'Nueva actividad interna' }}</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <x-text-input name="title" label="Título" :value="$activity?->title" :required="true" help="Ej.: Capacitación del equipo / Reunión general."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="location" label="Lugar" :value="$activity?->location" help="Sala, dirección o enlace."/>
                </div>
                <div class="col-md-6">
                    <x-text-input name="starts_at" type="datetime-local" label="Inicio" :value="$defaultStart" :required="true"/>
                </div>
                <div class="col-md-6">
                    <x-text-input name="ends_at" type="datetime-local" label="Fin" :value="$activity?->ends_at?->format('Y-m-d\TH:i')"/>
                </div>
                <div class="col-md-6">
                    <x-select name="recurrence_frequency" label="Repetición" :options="\App\Models\InternalActivity::FREQUENCY_LABELS"
                              :value="$activity?->recurrence_frequency" placeholder="No se repite"/>
                </div>
                <div class="col-md-6">
                    <x-text-input name="recurrence_until" type="date" label="Repetir hasta" :value="$activity?->recurrence_until?->format('Y-m-d')"
                                  help="Obligatorio si se repite. Máximo un año."/>
                </div>
                <div class="col-md-12">
                    <x-label for="description" label="Descripción"/>
                    <textarea name="description" id="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description', $activity?->description) }}</textarea>
                    <x-validation-error name="description"/>
                </div>

                <div class="col-md-12">
                    <x-select name="audience_type" label="¿Quién la ve?" :options="\App\Models\InternalActivity::AUDIENCE_LABELS"
                              :value="$audience" :required="true" data-audience-select/>
                </div>
                <div class="col-md-12" data-audience-pane="roles" @if ($audience !== 'roles') hidden @endif>
                    <x-label for="role_ids" label="Roles" :required="true"/>
                    <select name="role_ids[]" id="role_ids" multiple size="5" class="form-select @error('role_ids') is-invalid @enderror">
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(in_array($role->id, $selectedRoles, true))>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Ctrl/Cmd + clic para elegir varios.</div>
                    <x-validation-error name="role_ids"/>
                </div>
                <div class="col-md-12" data-audience-pane="users" @if ($audience !== 'users') hidden @endif>
                    <x-label for="user_ids" label="Personas" :required="true"/>
                    <select name="user_ids[]" id="user_ids" multiple size="6" class="form-select @error('user_ids') is-invalid @enderror">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(in_array($user->id, $selectedUsers, true))>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Ctrl/Cmd + clic para elegir varios.</div>
                    <x-validation-error name="user_ids"/>
                </div>

                <div class="col-md-4">
                    <x-select name="responsible_id" label="Responsable"
                              :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name])->all()"
                              :value="$responsibleId ?: null" placeholder="Sin responsable"/>
                </div>
                <div class="col-md-8">
                    <x-label for="participant_ids" label="Participantes"/>
                    <select name="participant_ids[]" id="participant_ids" multiple size="6" class="form-select @error('participant_ids') is-invalid @enderror">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(in_array($user->id, $selectedParticipants, true))>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Los participantes siempre ven la actividad, aunque no estén en la audiencia.</div>
                    <x-validation-error name="participant_ids"/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ $isEdit ? route('internal-activities.show', $activity) : route('internal-activities.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar cambios' : 'Crear actividad' }}</button>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const select = document.querySelector('[data-audience-select]');
        if (!select) return;
        const sync = () => document.querySelectorAll('[data-audience-pane]').forEach((pane) => {
            pane.hidden = pane.dataset.audiencePane !== select.value;
        });
        select.addEventListener('change', sync);
    });
</script>
