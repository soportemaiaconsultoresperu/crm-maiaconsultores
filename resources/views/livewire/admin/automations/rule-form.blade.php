{{-- B12-UI — RuleForm Blade view.
     UX model: CUANDO ocurre → SI cumple condiciones → ENTONCES ejecutar acciones. --}}
@php
    $triggerLabels = [
        'LeadCreated' => 'Se crea un prospecto',
        'LeadAssigned' => 'Se asigna un prospecto',
        'LeadStatusChanged' => 'Cambia el estado de un prospecto',
        'LeadDeactivated' => 'Se desactiva un prospecto',
        'LeadConverted' => 'Un prospecto se convierte en cliente',
        'OpportunityCreated' => 'Se crea una oportunidad',
        'OpportunityStageChanged' => 'Cambia la etapa de una oportunidad',
        'OpportunityWon' => 'Una oportunidad se marca como ganada',
        'OpportunityLost' => 'Una oportunidad se marca como perdida',
        'QuotationCreated' => 'Se crea una cotización',
        'QuotationSent' => 'Se envía una cotización',
        'QuotationAccepted' => 'Una cotización es aceptada',
        'ActivityCompleted' => 'Se completa una actividad',
        'ActivityOverdue' => 'Una actividad queda vencida',
        'QuotationWillExpire' => 'Una cotización está por vencer',
        'CustomerIdle' => 'Un cliente queda sin seguimiento',
        'ContactPrimaryChanged' => 'Cambia el contacto principal',
        'ContactDeactivated' => 'Se desactiva un contacto',
        'CustomerDeactivated' => 'Se desactiva un cliente',
    ];
    $triggerHelp = [
        'LeadCreated' => 'Útil para contactar rápido a prospectos nuevos.',
        'LeadStatusChanged' => 'Útil para mover tareas cuando cambia el avance comercial.',
        'OpportunityWon' => 'Útil para iniciar entrega, facturación o postventa.',
        'ActivityOverdue' => 'Útil para avisar cuando hay seguimientos atrasados.',
        'QuotationWillExpire' => 'Útil para recordar cotizaciones próximas a vencer.',
        'CustomerIdle' => 'Útil para reactivar clientes sin actividad reciente.',
    ];
    $actionTypeLabels = [
        'create_activity' => 'Crear una actividad de seguimiento',
        'assign_owner' => 'Asignar un responsable',
        'change_status' => 'Cambiar estado',
        'change_stage' => 'Cambiar etapa de oportunidad',
        'add_tag' => 'Agregar etiqueta',
        'send_notification' => 'Enviar notificación interna',
        'send_email' => 'Enviar correo',
        'send_whatsapp_template' => 'Enviar plantilla de WhatsApp',
        'create_follow_up_activity' => 'Crear actividad de seguimiento futuro',
        'add_note' => 'Agregar nota al historial',
        'webhook' => 'Enviar datos a otro sistema',
    ];
    $actionTypeIcons = [
        'create_activity' => 'bi-calendar-plus',
        'assign_owner' => 'bi-person-check',
        'change_status' => 'bi-arrow-repeat',
        'change_stage' => 'bi-kanban',
        'add_tag' => 'bi-tags',
        'send_notification' => 'bi-bell',
        'send_email' => 'bi-envelope',
        'send_whatsapp_template' => 'bi-chat-dots',
        'create_follow_up_activity' => 'bi-calendar2-week',
        'add_note' => 'bi-journal-text',
        'webhook' => 'bi-broadcast',
    ];
    $selectedTriggerBase = class_basename($trigger_event ?: '');
    $selectedTriggerLabel = $selectedTriggerBase ? ($triggerLabels[$selectedTriggerBase] ?? $selectedTriggerBase) : 'Elegí un evento para empezar';
    $conditionGroupCount = is_countable($groups ?? []) ? count($groups) : 0;
    $actionCount = is_countable($actions ?? []) ? count($actions) : 0;
@endphp

<div class="automation-builder">
    <div class="card lead-form-hero automation-builder-hero mb-4">
        <div class="card-body">
            <div class="row g-4 align-items-center">
                <div class="col-xl-7">
                    <p class="automation-eyebrow mb-2">Constructor visual de automatizaciones</p>
                    <h2 class="h3 mb-2">Diseñá el flujo como lo explicaría tu equipo comercial</h2>
                    <p class="text-secondary mb-0">Inspirado en un constructor por nodos, pero simplificado para CRM: <strong>cuando ocurre un evento</strong>, <strong>si se cumplen condiciones</strong>, <strong>entonces se ejecutan acciones</strong>.</p>
                </div>
                <div class="col-xl-5">
                    <div class="automation-hero-map" aria-label="Mapa visual de la automatización">
                        <div class="automation-hero-node automation-hero-node-trigger">
                            <i class="bi bi-lightning-charge" aria-hidden="true"></i>
                            <span>CUANDO</span>
                        </div>
                        <i class="bi bi-arrow-right-short automation-hero-arrow" aria-hidden="true"></i>
                        <div class="automation-hero-node automation-hero-node-condition">
                            <i class="bi bi-funnel" aria-hidden="true"></i>
                            <span>SI</span>
                        </div>
                        <i class="bi bi-arrow-right-short automation-hero-arrow" aria-hidden="true"></i>
                        <div class="automation-hero-node automation-hero-node-action">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                            <span>ENTONCES</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST"
          action="{{ $mode === 'edit' ? route('admin.automations.update', $ruleId) : route('admin.automations.store') }}"
          data-swal-loading>
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="automation-builder-layout">
            <div class="automation-main-column">
                <div class="automation-settings-card mb-4">
                    <div class="automation-node-heading mb-3">
                        <span class="automation-node-icon automation-node-icon-neutral"><i class="bi bi-sliders" aria-hidden="true"></i></span>
                        <div>
                            <p class="automation-eyebrow mb-1">Configuración general</p>
                            <h3 class="h5 mb-1">Información básica de la regla</h3>
                            <p class="text-secondary small mb-0">Dale un nombre que cualquier persona entienda. Ejemplo: “Avisar prospecto web de alto interés”.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="rule-name" class="form-label">Nombre claro de la regla</label>
                            <input type="text" id="rule-name" name="name" class="form-control @error('name') is-invalid @enderror" wire:model="name" placeholder="Ej. Seguimiento automático de prospectos web" required>
                            <x-validation-error name="name" />
                        </div>
                        <div class="col-md-4">
                            <label for="rule-order" class="form-label">Prioridad</label>
                            <input type="number" id="rule-order" name="order" min="1" class="form-control @error('order') is-invalid @enderror" wire:model="order">
                            <div class="form-text">1 se evalúa antes que 2. Usalo sólo si hay varias reglas parecidas.</div>
                            <x-validation-error name="order" />
                        </div>
                        <div class="col-12">
                            <label for="rule-description" class="form-label">Descripción para el equipo</label>
                            <textarea id="rule-description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror" wire:model="description" placeholder="Explicá en una frase qué problema resuelve esta automatización."></textarea>
                            <x-validation-error name="description" />
                        </div>
                    </div>
                </div>

                <div class="automation-flow-canvas" aria-label="Constructor visual de flujo">
                    <section class="automation-flow-node automation-flow-node-trigger">
                        <div class="automation-node-heading">
                            <span class="automation-node-icon"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span>
                            <div>
                                <p class="automation-eyebrow mb-1">Nodo 1 · CUANDO</p>
                                <h3 class="h5 mb-1">Evento que inicia la automatización</h3>
                                <p class="text-secondary small mb-0">Elegí el hecho del CRM que pone la regla en marcha.</p>
                            </div>
                        </div>
                        <div class="automation-node-fields mt-3">
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <label for="rule-trigger" class="form-label">Evento que inicia la automatización</label>
                                    <select id="rule-trigger" name="trigger_event" class="form-select @error('trigger_event') is-invalid @enderror" wire:model.live="trigger_event" required>
                                        <option value="">— Elegí qué tiene que pasar —</option>
                                        @foreach ($this->triggers as $fqcn)
                                            @php
                                                $base = class_basename($fqcn);
                                            @endphp
                                            <option value="{{ $fqcn }}">{{ $triggerLabels[$base] ?? $base }}</option>
                                        @endforeach
                                    </select>
                                    @if ($selectedTriggerBase)
                                        <div class="form-text">{{ $triggerHelp[$selectedTriggerBase] ?? 'Este evento dispara la revisión de condiciones y acciones.' }}</div>
                                    @endif
                                    <x-validation-error name="trigger_event" />
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-label d-block">Modo de ejecución</label>
                                    <div class="btn-group btn-group-sm automation-mode-toggle" role="group" aria-label="Modo de la regla">
                                        <input type="radio" id="mode-test" name="mode" value="test" class="btn-check" wire:model="ruleMode">
                                        <label for="mode-test" class="btn btn-outline-primary">Prueba segura</label>
                                        <input type="radio" id="mode-live" name="mode" value="live" class="btn-check" wire:model="ruleMode">
                                        <label for="mode-live" class="btn btn-outline-primary">Producción</label>
                                    </div>
                                    <div class="form-text">En prueba podés validar sin afectar datos reales. En producción se ejecuta automáticamente.</div>
                                    <x-validation-error name="mode" />
                                </div>
                                <div class="col-lg-3">
                                    <div class="automation-active-switch h-100">
                                        <div class="form-check form-switch mb-1">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" id="rule-is-active" name="is_active" value="1" class="form-check-input" wire:model.boolean="is_active">
                                            <label for="rule-is-active" class="form-check-label">Habilitar regla</label>
                                        </div>
                                        <div class="form-text">Si está apagada, queda guardada pero no se ejecuta.</div>
                                        <x-validation-error name="is_active" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="automation-flow-connector" aria-hidden="true">
                        <span></span>
                        <i class="bi bi-arrow-down-short"></i>
                    </div>

                    <section class="automation-flow-node automation-flow-node-condition">
                        <div class="automation-node-heading">
                            <span class="automation-node-icon"><i class="bi bi-funnel" aria-hidden="true"></i></span>
                            <div>
                                <p class="automation-eyebrow mb-1">Nodo 2 · SI</p>
                                <h3 class="h5 mb-1">Condiciones del negocio</h3>
                                <p class="text-secondary small mb-0">Filtrá cuándo sí debe actuar. Ejemplo: origen = Facebook Ads y nivel de interés = alto.</p>
                            </div>
                        </div>
                        <div class="automation-node-fields mt-3">
                            <div wire:sort="reorderGroups" data-testid="rule-form-groups" class="automation-condition-stack mb-3">
                                @foreach ($groups as $index => $group)
                                        <div data-testid="rule-form-group-row" wire:key="group-{{ $index }}" wire:sort:item="{{ $index }}">
                                            <livewire:admin.automations.condition-group-editor
                                                :group="$group['conditions'] ?? []"
                                                :groupIndex="$index"
                                                :logicalOperator="$group['logical_operator'] ?? 'AND'"
                                                :wire:key="'group-'.$index" />
                                            @if ($conditionGroupCount > 1)
                                                <div class="d-flex justify-content-end mt-2">
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            wire:click="removeGroup({{ $index }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="removeGroup({{ $index }})"
                                                            aria-label="Quitar bloque {{ $index + 1 }}">
                                                        <span wire:loading.remove wire:target="removeGroup({{ $index }})">
                                                            <i class="bi bi-trash me-1" aria-hidden="true"></i> Quitar bloque
                                                        </span>
                                                        <span wire:loading wire:target="removeGroup({{ $index }})">
                                                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Quitando…
                                                        </span>
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                @endforeach
                            </div>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        wire:click="addGroup"
                                        wire:loading.attr="disabled"
                                        wire:target="addGroup"
                                        aria-label="Agregar otro bloque de condiciones">
                                    <span wire:loading.remove wire:target="addGroup">
                                        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar otro bloque de condiciones
                                    </span>
                                    <span wire:loading wire:target="addGroup">
                                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Agregando…
                                    </span>
                                </button>
                        </div>
                    </section>

                    <div class="automation-flow-connector" aria-hidden="true">
                        <span></span>
                        <i class="bi bi-arrow-down-short"></i>
                    </div>

                    <section class="automation-flow-node automation-flow-node-action">
                        <div class="automation-node-heading">
                            <span class="automation-node-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                            <div>
                                <p class="automation-eyebrow mb-1">Nodo 3 · ENTONCES</p>
                                <h3 class="h5 mb-1">Acciones que ejecuta el CRM</h3>
                                <p class="text-secondary small mb-0">Definí qué debe hacer el CRM y en qué orden. Cada tarjeta es una acción del flujo.</p>
                            </div>
                        </div>
                        <div class="automation-node-fields mt-3">
                            <div wire:sort="reorderActions" data-testid="rule-form-actions" class="automation-action-stack mb-3">
                                @foreach ($actions as $index => $action)
                                    @php
                                        $actionType = $action['type'] ?? 'add_tag';
                                        $actionLabel = $actionTypeLabels[$actionType] ?? $actionType;
                                        $actionIcon = $actionTypeIcons[$actionType] ?? 'bi-gear';
                                    @endphp
                                    <div data-testid="rule-form-action-row" wire:key="action-{{ $index }}" wire:sort:item="{{ $index }}">
                                        <article class="automation-action-node">
                                            <div class="automation-action-node-header">
                                                <div class="d-flex align-items-center gap-2 min-w-0">
                                                    <span class="automation-action-order">{{ $index + 1 }}</span>
                                                    <span class="automation-action-icon"><i class="bi {{ $actionIcon }}" aria-hidden="true"></i></span>
                                                    <div class="min-w-0">
                                                        <p class="automation-eyebrow mb-0">Acción activa del flujo</p>
                                                        <h4 class="h6 mb-0 text-truncate">{{ $actionLabel }}</h4>
                                                    </div>
                                                </div>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            wire:click="removeAction({{ $index }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="removeAction({{ $index }})"
                                                            aria-label="Quitar acción {{ $index + 1 }}">
                                                        <span wire:loading.remove wire:target="removeAction({{ $index }})">
                                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                                                        </span>
                                                        <span wire:loading wire:target="removeAction({{ $index }})">
                                                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Quitando…
                                                        </span>
                                                    </button>
                                            </div>
                                            <div class="automation-action-node-body">
                                                <div class="row g-3 align-items-end">
                                                    <div class="col-lg-4">
                                                        <label class="form-label small mb-1" for="action-type-{{ $index }}">Qué acción debe hacer</label>
                                                        <select id="action-type-{{ $index }}" class="form-select form-select-sm" name="actions[{{ $index }}][type]" wire:model.live="actions.{{ $index }}.type">
                                                            @foreach ($this->actionTypes as $type)
                                                                <option value="{{ $type }}">
                                                                    {{ $actionTypeLabels[$type] ?? $type }}@if (in_array($type, ['send_whatsapp_template', 'webhook'], true)) — sólo prueba segura @endif
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="col-lg-3">
                                                        <label class="form-label small mb-1" for="action-channel-{{ $index }}">Dónde se ejecuta</label>
                                                        <input type="text" id="action-channel-{{ $index }}" class="form-control form-control-sm" name="actions[{{ $index }}][channel]" wire:model="actions.{{ $index }}.channel" placeholder="Ej. CRM, correo, WhatsApp">
                                                        <div class="form-text">Mantené el canal que usará esta acción; no cambia a quién impacta.</div>
                                                    </div>
                                                    <div class="col-lg-3">
                                                        <label class="form-label small mb-1" for="action-recipient-{{ $index }}">Quién recibe o queda asignado</label>
                                                        <input type="text" id="action-recipient-{{ $index }}" class="form-control form-control-sm" name="actions[{{ $index }}][recipient_strategy]" wire:model="actions.{{ $index }}.recipient_strategy" placeholder="Ej. responsable actual, usuario, equipo">
                                                        <div class="form-text">Usá una descripción de negocio; cada acción puede ajustar el destino en sus datos.</div>
                                                    </div>
                                                    <div class="col-lg-2">
                                                        <div class="automation-action-toggle">
                                                            <div class="form-check form-switch mb-0">
                                                                <input type="hidden" name="actions[{{ $index }}][is_active]" value="0">
                                                                <input type="checkbox" id="action-active-{{ $index }}" class="form-check-input" name="actions[{{ $index }}][is_active]" value="1" wire:model.boolean="actions.{{ $index }}.is_active">
                                                                <label class="form-check-label small" for="action-active-{{ $index }}">Usar acción</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="automation-action-payload">
                                                            <label class="form-label small mb-2">Datos necesarios para esta acción</label>
                                                            <livewire:admin.automations.action-editor
                                                                :actionIndex="$index"
                                                                :action="$action"
                                                                :editorUserId="auth()->id() ?? 0"
                                                                :wire:key="'action-editor-' . $index . '-' . ($action['type'] ?? 'add_tag')" />
                                                            @if (in_array($action['type'] ?? '', ['send_whatsapp_template', 'webhook'], true))
                                                                <div class="form-text text-warning">Todavía no está lista para producción; usala sólo para revisar la configuración en prueba segura.</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="actions[{{ $index }}][position]" value="{{ $action['position'] ?? ($index + 1) }}">
                                                </div>
                                            </div>
                                        </article>
                                    </div>
                                @endforeach
                            </div>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        wire:click="addAction"
                                        wire:loading.attr="disabled"
                                        wire:target="addAction"
                                        aria-label="Agregar otra acción">
                                    <span wire:loading.remove wire:target="addAction">
                                        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar otra acción
                                    </span>
                                    <span wire:loading wire:target="addAction">
                                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Agregando…
                                    </span>
                                </button>
                        </div>
                    </section>
                </div>
            </div>

            <aside class="automation-summary-column">
                <div class="automation-human-summary" aria-label="Resumen humano de la automatización">
                    <p class="automation-eyebrow mb-2">Resumen humano</p>
                    <h3 class="h6 mb-3">Así se leerá la regla</h3>
                    <div class="automation-summary-line automation-summary-line-trigger">
                        <span>Cuando</span>
                        <strong>{{ $selectedTriggerLabel }}</strong>
                    </div>
                    <div class="automation-summary-line automation-summary-line-condition">
                        <span>Si</span>
                        <strong>{{ $conditionGroupCount === 1 ? 'cumple el bloque de condiciones configurado' : 'cumple los bloques de condiciones configurados' }}</strong>
                    </div>
                    <div class="automation-summary-line automation-summary-line-action">
                        <span>Entonces</span>
                        <strong>{{ $actionCount === 1 ? 'ejecuta la acción activa definida' : 'ejecuta las acciones activas definidas' }}</strong>
                    </div>
                    <div class="automation-summary-meta">
                        <span><i class="bi bi-diagram-3" aria-hidden="true"></i> {{ $conditionGroupCount }} {{ $conditionGroupCount === 1 ? 'bloque' : 'bloques' }}</span>
                        <span><i class="bi bi-check2-square" aria-hidden="true"></i> {{ $actionCount }} {{ $actionCount === 1 ? 'acción' : 'acciones' }}</span>
                    </div>
                    <p class="text-secondary small mb-0">Este resumen es una vista de lectura rápida; los valores reales se guardan desde los campos del formulario.</p>
                </div>
            </aside>
        </div>

        <input type="hidden" name="owner_id" value="{{ $owner_id ?? '' }}">

        <div class="automation-submit-bar mt-4">
            <a href="{{ route('admin.automations.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit"
                    class="btn btn-primary"
                    data-swal-confirm
                    data-swal-title="Guardar automatización"
                    data-swal-text="Se guardará la receta CUANDO / SI / ENTONCES con las condiciones y acciones configuradas."
                    data-swal-type="question"
                    data-swal-confirm-text="Sí, guardar">
                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Guardar automatización
            </button>
        </div>
    </form>
</div>
