{{--
    Team create/edit form partial.
    Variables: $team (Team model), $supervisors (Collection<User>).
--}}
@csrf

<div class="row g-3">
    <div class="col-md-6">
        <x-text-input name="name" label="Nombre del equipo" :value="$team->name" required/>
    </div>
    <div class="col-md-6">
        <x-select
            name="supervisor_id"
            label="Supervisor"
            :options="$supervisors->pluck('name', 'id')->all()"
            :value="$team->supervisor_id"
            placeholder="Selecciona un supervisor"
            required
        />
    </div>

    <div class="col-md-6 d-flex align-items-end">
        <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                   @checked(old('is_active', $team->is_active ?? true))>
            <label class="form-check-label" for="is_active">Equipo activo</label>
        </div>
    </div>
</div>