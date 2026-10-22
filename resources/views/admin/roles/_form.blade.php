{{--
    Role create/edit form (odd/tasks/roles-permissions-ux.md, S4).
    Variables: $role, $fullAccess (bool), $modules (PermissionCatalog::grouped), $selectedPermissions (list of names),
    $selectedScopes (scopeKey => none|own|team|any), $action, $method ('POST'|'PUT'), $submitLabel, $cancelLabel.
    Read scopes render as one radio group per module; every other permission is a switch. The scope radio maps
    to exactly one `<module>.view.<scope>` permission on save (RoleController::resolvePermissions).
--}}
@php
    $scopeOptions = ['none' => 'Sin acceso', 'own' => 'Solo los suyos', 'team' => 'Los de su equipo', 'any' => 'Todos'];
    $selectedSet = array_flip($selectedPermissions);
    $totalSelected = 0;
@endphp

<form method="POST" action="{{ $action }}" id="role-form" class="card">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card-body">
        @if ($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        <div class="row">
            <div class="col-md-6">
                @if ($role->exists && in_array($role->name, $protectedRoles ?? [], true))
                    <x-text-input name="name" label="Nombre del rol" :value="$role->name" required readonly
                                  help="El nombre de los roles del sistema no se puede cambiar: el CRM los reconoce por su nombre."/>
                @else
                    <x-text-input name="name" label="Nombre del rol" :value="$role->name" required
                                  help="Use un nombre corto que describa el puesto, en minúsculas (ej. «coordinador»). Se mostrará con mayúscula inicial."/>
                @endif
            </div>
        </div>

        @if ($fullAccess)
            <div class="alert alert-info d-flex gap-3 mt-4 mb-0" role="note">
                <i class="bi bi-shield-check fs-3" aria-hidden="true"></i>
                <div>
                    <h2 class="h6 mb-1">Acceso total: el administrador puede todo, siempre</h2>
                    <p class="mb-1 small">
                        El sistema concede al rol administrador todas las acciones sin revisar permisos individuales.
                        Por eso aquí no se eligen permisos: quitarle uno no le restringiría nada.
                    </p>
                    <p class="mb-0 small">
                        Si necesita un rol con acceso limitado, cree un rol nuevo; puede copiar los permisos de otro rol como punto de partida.
                    </p>
                </div>
            </div>
        @else
            <div class="d-flex flex-wrap align-items-end gap-2 mt-4 mb-3">
                <div class="flex-grow-1">
                    <label for="permission-search" class="form-label fw-medium mb-1">Permisos del rol</label>
                    <input type="search" id="permission-search" class="form-control"
                           placeholder="Buscar permiso (ej. exportar, clientes, contraseñas)..." autocomplete="off">
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-secondary" data-expand-all>
                        <i class="bi bi-arrows-expand me-1" aria-hidden="true"></i> Expandir todo
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-collapse-all>
                        <i class="bi bi-arrows-collapse me-1" aria-hidden="true"></i> Contraer todo
                    </button>
                </div>
            </div>
            <p class="small text-secondary">
                Abra cada módulo para elegir qué registros puede ver la persona y qué acciones puede realizar.
                Los permisos marcados como <span class="badge text-bg-warning">Sensible</span> permiten borrar, exportar o administrar información importante.
            </p>

            <p class="text-secondary d-none" data-search-empty>No hay permisos que coincidan con la búsqueda.</p>

            @foreach ($modules as $module)
                @php
                    $collapseId = 'module-'.\Illuminate\Support\Str::slug($module['key']);
                    $itemCount = collect($module['groups'])->sum(fn ($g) => count($g['items']));
                    $checkedCount = collect($module['groups'])->flatMap(fn ($g) => $g['items'])
                        ->filter(fn ($item) => isset($selectedSet[$item['name']]))->count();
                    $currentScope = $module['scopeKey'] ? ($selectedScopes[$module['scopeKey']] ?? 'none') : null;
                    $moduleTotal = $itemCount + ($module['scope'] ? 1 : 0);
                    $moduleSelected = $checkedCount + ($currentScope !== null && $currentScope !== 'none' ? 1 : 0);
                    $totalSelected += $moduleSelected;
                    $moduleSearch = \Illuminate\Support\Str::lower($module['name']);
                @endphp
                <div class="card mb-2" data-module="{{ $module['key'] }}">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2 py-2">
                        <button type="button" class="btn btn-link text-body text-decoration-none p-0 d-flex align-items-center gap-2 flex-grow-1 text-start"
                                data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                            <i class="bi {{ $module['icon'] }} fs-5" aria-hidden="true"></i>
                            <span class="fw-semibold">{{ $module['name'] }}</span>
                            <span class="badge rounded-pill text-bg-light border" data-module-counter>{{ $moduleSelected }} de {{ $moduleTotal }}</span>
                            <i class="bi bi-chevron-down ms-auto small" aria-hidden="true"></i>
                        </button>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Selección rápida de {{ $module['name'] }}">
                            <button type="button" class="btn btn-outline-secondary" data-module-all>Marcar todo</button>
                            <button type="button" class="btn btn-outline-secondary" data-module-none>Quitar todo</button>
                        </div>
                    </div>
                    <div class="collapse" id="{{ $collapseId }}">
                        <div class="card-body">
                            @if ($module['scope'])
                                <fieldset class="mb-3" data-search="{{ $moduleSearch }} ver registros que puede ver todos su equipo suyos">
                                    <legend class="fs-6 fw-medium mb-1">¿Qué registros puede ver?</legend>
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach ($scopeOptions as $value => $text)
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio"
                                                       name="scope[{{ $module['scopeKey'] }}]"
                                                       id="{{ $collapseId }}-scope-{{ $value }}"
                                                       value="{{ $value }}" @checked($currentScope === $value)>
                                                <label class="form-check-label" for="{{ $collapseId }}-scope-{{ $value }}">{{ $text }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="form-text">Elija una sola opción: «Todos» incluye los de su equipo, y estos incluyen los suyos.</div>
                                </fieldset>
                            @endif

                            @foreach ($module['groups'] as $group)
                                <div data-group>
                                    <h3 class="h6 text-uppercase text-secondary small fw-semibold mt-3 mb-2">{{ $group['label'] }}</h3>
                                    @foreach ($group['items'] as $item)
                                        @php($inputId = 'perm-'.md5($item['name']))
                                        <div class="form-check form-switch mb-2"
                                             data-search="{{ $moduleSearch }} {{ \Illuminate\Support\Str::lower($item['label'].' '.$item['description']) }}">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   name="permissions[]" value="{{ $item['name'] }}" id="{{ $inputId }}"
                                                   aria-describedby="{{ $inputId }}-help"
                                                   @checked(isset($selectedSet[$item['name']]))>
                                            <label class="form-check-label" for="{{ $inputId }}">
                                                {{ $item['label'] }}
                                                @if ($item['sensitive'])
                                                    <span class="badge text-bg-warning ms-1">
                                                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Sensible
                                                    </span>
                                                @endif
                                            </label>
                                            <div id="{{ $inputId }}-help" class="form-text mt-0">{{ $item['description'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <div class="card-footer d-flex flex-wrap align-items-center gap-2 position-sticky bottom-0 bg-body border-top shadow-sm" style="z-index: 5;">
        @unless ($fullAccess)
            <span class="text-secondary"><strong class="text-body" data-total-selected>{{ $totalSelected }}</strong> permisos seleccionados</span>
        @endunless
        <div class="ms-auto d-flex gap-2">
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">{{ $cancelLabel }}</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> {{ $submitLabel }}
            </button>
        </div>
    </div>
</form>

@unless ($fullAccess)
    @push('scripts')
        <script>
            (function () {
                'use strict';

                var form = document.getElementById('role-form');
                if (form === null) {
                    return;
                }

                var normalize = function (text) {
                    return (text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                };
                var modules = form.querySelectorAll('[data-module]');

                function refreshCounters() {
                    var total = 0;
                    modules.forEach(function (card) {
                        var switches = card.querySelectorAll('input[name="permissions[]"]');
                        var selected = 0;
                        var available = switches.length;
                        switches.forEach(function (input) {
                            if (input.checked) {
                                selected++;
                            }
                        });
                        if (card.querySelector('input[type="radio"]') !== null) {
                            available++;
                            var scope = card.querySelector('input[type="radio"]:checked');
                            if (scope !== null && scope.value !== 'none') {
                                selected++;
                            }
                        }
                        card.querySelector('[data-module-counter]').textContent = selected + ' de ' + available;
                        total += selected;
                    });
                    form.querySelector('[data-total-selected]').textContent = total;
                }

                function setExpanded(card, expanded) {
                    var body = card.querySelector('.collapse');
                    body.classList.toggle('show', expanded);
                    card.querySelector('[data-bs-toggle="collapse"]').setAttribute('aria-expanded', expanded ? 'true' : 'false');
                }

                form.addEventListener('change', refreshCounters);

                form.addEventListener('click', function (event) {
                    var all = event.target.closest('[data-module-all]');
                    var none = event.target.closest('[data-module-none]');
                    if (event.target.closest('[data-expand-all], [data-collapse-all]')) {
                        var expand = event.target.closest('[data-expand-all]') !== null;
                        modules.forEach(function (card) { setExpanded(card, expand); });
                        return;
                    }
                    if (all === null && none === null) {
                        return;
                    }
                    var card = event.target.closest('[data-module]');
                    card.querySelectorAll('input[name="permissions[]"]').forEach(function (input) {
                        input.checked = all !== null;
                    });
                    var radio = card.querySelector('input[type="radio"][value="' + (all !== null ? 'any' : 'none') + '"]');
                    if (radio !== null) {
                        radio.checked = true;
                    }
                    refreshCounters();
                });

                var search = document.getElementById('permission-search');
                search.addEventListener('input', function () {
                    var query = normalize(search.value).trim();
                    var anyVisible = false;

                    modules.forEach(function (card) {
                        var visible = 0;
                        card.querySelectorAll('[data-search]').forEach(function (item) {
                            var match = query === '' || normalize(item.dataset.search).indexOf(query) !== -1;
                            item.classList.toggle('d-none', !match);
                            if (match) {
                                visible++;
                            }
                        });
                        card.querySelectorAll('[data-group]').forEach(function (group) {
                            group.classList.toggle('d-none', group.querySelector('[data-search]:not(.d-none)') === null);
                        });
                        card.classList.toggle('d-none', visible === 0);
                        setExpanded(card, query !== '' && visible > 0);
                        anyVisible = anyVisible || visible > 0;
                    });

                    form.querySelector('[data-search-empty]').classList.toggle('d-none', anyVisible);
                });
            })();
        </script>
    @endpush
@endunless
