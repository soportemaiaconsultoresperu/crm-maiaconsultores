{{--
    User create/edit form partial.
    Variables: $user (User model with current values), $roles (array of role names), $passwordRequired (bool).
--}}
@csrf

<div class="row g-3">
    <div class="col-md-6">
        <x-text-input name="name" label="Nombre completo" :value="$user->name" required autocomplete="name"/>
    </div>
    <div class="col-md-6">
        <x-text-input name="email" label="Correo electrónico" type="email" :value="$user->email" required autocomplete="email"/>
    </div>

    @if (! empty($passwordRequired))
        <div class="col-md-6">
            <x-text-input
                name="password"
                label="Contraseña"
                type="password"
                autocomplete="new-password"
                help="Déjalo vacío para generar una contraseña temporal automáticamente."
            />
        </div>
    @endif

    <div class="col-md-6">
        <x-select
            name="role"
            label="Rol"
            :options="array_combine($roles, array_map(fn ($r) => ucfirst($r), $roles))"
            :value="$user->roles->first()?->name"
            placeholder="Sin rol"
        />
    </div>

    <div class="col-md-6 d-flex align-items-end">
        <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                   @checked(old('is_active', $user->is_active ?? true))>
            <label class="form-check-label" for="is_active">Usuario activo</label>
        </div>
    </div>
</div>