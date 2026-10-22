{{--
    User create/edit form partial.
    Variables: $user (User model with current values), $roles (array of role names), $passwordRequired (bool),
    $roleSummaries (role name => list of module names, or null for the full-access role; optional).
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
        @php($currentRole = (string) old('role', $user->roles->first()?->name))
        <div class="form-text" id="role-summaries" aria-live="polite">
            <div data-role-summary="" @class(['d-none' => $currentRole !== ''])>Sin rol, la persona no podrá usar ningún módulo.</div>
            @foreach ($roleSummaries ?? [] as $roleName => $moduleNames)
                <div data-role-summary="{{ $roleName }}" @class(['d-none' => $currentRole !== $roleName])>
                    @if ($moduleNames === null)
                        <i class="bi bi-shield-check text-success" aria-hidden="true"></i>
                        Acceso total: puede usar todo el sistema.
                    @elseif ($moduleNames === [])
                        Este rol aún no tiene permisos.
                    @else
                        Puede usar: {{ implode(', ', $moduleNames) }}.
                    @endif
                </div>
            @endforeach
        </div>
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

@push('scripts')
    <script>
        (function () {
            'use strict';

            var select = document.getElementById('role');
            var summaries = document.querySelectorAll('#role-summaries [data-role-summary]');
            if (select === null) {
                return;
            }

            select.addEventListener('change', function () {
                summaries.forEach(function (summary) {
                    summary.classList.toggle('d-none', summary.dataset.roleSummary !== select.value);
                });
            });
        })();
    </script>
@endpush