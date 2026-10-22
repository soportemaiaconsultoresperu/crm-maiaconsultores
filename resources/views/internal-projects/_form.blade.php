{{--
    Shared internal project create/edit form.

    Expected data: $project (null on create), $users, $roles.
    The audience selector shows the roles or users picker that matches the
    chosen audience; the server validates the pair, the script is only UX.
--}}
@php
    $isEdit = $project !== null;
    $audience = old('audience_type', $project?->audience_type ?? 'all');
    $selectedRoles = array_map('intval', old('role_ids', $project?->audienceRoles->pluck('id')->all() ?? []));
    $selectedUsers = array_map('intval', old('user_ids', $project?->audienceUsers->pluck('id')->all() ?? []));
    $selectedMembers = array_map('intval', old('member_ids', $project?->members->pluck('id')->all() ?? []));
    $userOptions = $users->mapWithKeys(fn ($u) => [$u->id => $u->name])->all();

    // Colour picker state: a preset, "Sin color" or a custom hex. After a
    // failed submit the previous choice is restored from old input.
    $palette = \App\Support\Content\ProjectColor::PALETTE;
    $oldColor = old('color', $project?->color);
    $currentColor = $oldColor === 'custom'
        ? \App\Support\Content\ProjectColor::normalize(old('color_custom'))
        : \App\Support\Content\ProjectColor::normalize($oldColor);
    $isCustom = $oldColor === 'custom' || ($currentColor !== null && ! array_key_exists($currentColor, $palette));
    $customValue = $currentColor ?? \App\Support\Content\ProjectColor::DEFAULT;
@endphp

<form method="POST"
      action="{{ $isEdit ? route('internal-projects.update', $project) : route('internal-projects.store') }}"
      data-testid="internal-project-form"
      data-swal-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">{{ $isEdit ? 'Editar proyecto interno' : 'Nuevo proyecto interno' }}</h3></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <x-text-input name="name" label="Nombre" :value="$project?->name" :required="true" help="Ej.: Desarrollo del nuevo CRM."/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="code" label="Código" :value="$project?->code" :required="true" help="Único. Ej.: CRM-2027."/>
                </div>
                <div class="col-md-12">
                    <x-label for="description" label="Descripción"/>
                    <textarea name="description" id="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description', $project?->description) }}</textarea>
                    <x-validation-error name="description"/>
                </div>

                <div class="col-md-12">
                    <fieldset data-testid="internal-project-color-palette">
                        <legend class="form-label fs-6 mb-1">Color</legend>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <input type="radio" class="btn-check" name="color" id="color-none" value=""
                                   @checked($currentColor === null && ! $isCustom)>
                            <label class="btn btn-sm btn-outline-secondary" for="color-none">Sin color</label>

                            @foreach ($palette as $hex => $label)
                                <input type="radio" class="btn-check" name="color" id="color-{{ ltrim($hex, '#') }}" value="{{ $hex }}"
                                       @checked(! $isCustom && $currentColor === $hex)>
                                <label class="content-color-swatch" for="color-{{ ltrim($hex, '#') }}"
                                       @style(['background-color: '.$hex]) title="{{ $label }}">
                                    <span class="visually-hidden">{{ $label }}</span>
                                </label>
                            @endforeach

                            <input type="radio" class="btn-check" name="color" id="color-custom" value="custom" @checked($isCustom)>
                            <label class="btn btn-sm btn-outline-secondary" for="color-custom">Personalizado</label>
                            <input type="color" name="color_custom" id="color_custom" value="{{ $customValue }}"
                                   class="form-control form-control-color" title="Elegir un color personalizado"
                                   aria-label="Color personalizado"
                                   oninput="document.getElementById('color-custom').checked = true">
                        </div>
                        <div class="form-text">Opcional. Identifica al proyecto en el resumen, la línea de tiempo y el calendario.</div>
                        <x-validation-error name="color"/>
                    </fieldset>
                </div>

                <div class="col-md-4">
                    <x-text-input name="starts_on" type="date" label="Inicio planificado" :value="$project?->starts_on?->format('Y-m-d')"/>
                </div>
                <div class="col-md-4">
                    <x-text-input name="ends_on" type="date" label="Fin planificado" :value="$project?->ends_on?->format('Y-m-d')"/>
                </div>
                <div class="col-md-4">
                    <x-select name="status" label="Estado" :options="\App\Enums\InternalProjects\ProjectStatus::labels()"
                              :value="$project?->status?->value ?? 'planificado'" :required="true"/>
                </div>

                <div class="col-md-4">
                    <x-select name="responsible_user_id" label="Responsable" :options="$userOptions"
                              :value="$project?->responsible_user_id" :placeholder="$isEdit ? null : 'Yo (quien crea el proyecto)'"
                              help="Edita el proyecto, su equipo y su audiencia."/>
                </div>
                <div class="col-md-8">
                    <x-label for="member_ids" label="Equipo"/>
                    <select name="member_ids[]" id="member_ids" multiple class="form-select @error('member_ids') is-invalid @enderror">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(in_array($user->id, $selectedMembers, true))>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">El equipo crea y actualiza fases, tareas y estados. El responsable siempre forma parte del equipo.</div>
                    <x-validation-error name="member_ids"/>
                </div>

                <div class="col-md-12">
                    <x-select name="audience_type" label="¿Quién lo ve?" :options="\App\Models\InternalProjects\InternalProject::AUDIENCE_LABELS"
                              :value="$audience" :required="true" data-audience-select
                              help="La audiencia solo ve el proyecto y su avance; el equipo siempre lo ve."/>
                </div>
                <div class="col-md-12" data-audience-pane="roles" @if ($audience !== 'roles') hidden @endif>
                    <x-label for="role_ids" label="Roles" :required="true"/>
                    <select name="role_ids[]" id="role_ids" multiple class="form-select @error('role_ids') is-invalid @enderror">
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(in_array($role->id, $selectedRoles, true))>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <x-validation-error name="role_ids"/>
                </div>
                <div class="col-md-12" data-audience-pane="users" @if ($audience !== 'users') hidden @endif>
                    <x-label for="user_ids" label="Personas" :required="true"/>
                    <select name="user_ids[]" id="user_ids" multiple class="form-select @error('user_ids') is-invalid @enderror">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(in_array($user->id, $selectedUsers, true))>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <x-validation-error name="user_ids"/>
                </div>

                @unless ($isEdit)
                    <div class="col-md-12">
                        <input type="hidden" name="create_default_phases" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="create_default_phases" id="create_default_phases" value="1"
                                   @checked(old('create_default_phases', '1') === '1')>
                            <label class="form-check-label" for="create_default_phases">
                                Crear fases sugeridas (Análisis, Diseño, Desarrollo, Pruebas, Lanzamiento)
                            </label>
                        </div>
                    </div>
                @endunless
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ $isEdit ? route('internal-projects.show', $project) : route('internal-projects.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar cambios' : 'Crear proyecto' }}</button>
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
