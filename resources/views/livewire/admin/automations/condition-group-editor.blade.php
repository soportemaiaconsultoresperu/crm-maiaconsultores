{{-- B12-UI — PR 3 / Stage 3B-1 — ConditionGroupEditor Blade view. --}}
@php
    $logicalOperatorLabels = [
        'AND' => 'Todas las condiciones',
        'OR' => 'Cualquiera de las condiciones',
    ];
    $logicalOperatorHelp = [
        'AND' => 'El CRM actuará sólo si se cumplen todas las filas de este bloque.',
        'OR' => 'El CRM actuará si se cumple cualquiera de las filas de este bloque.',
    ];
    $operatorLabels = [
        'eq' => 'es igual a',
        'neq' => 'no es',
        'gt' => 'mayor que',
        'gte' => 'mayor o igual que',
        'lt' => 'menor que',
        'lte' => 'menor o igual que',
        'in' => 'está dentro de',
        'not_in' => 'no está dentro de',
        'contains' => 'contiene',
        'starts_with' => 'comienza con',
        'ends_with' => 'termina con',
        'is_null' => 'está vacío',
        'is_not_null' => 'tiene dato',
        'before' => 'antes de',
        'after' => 'después de',
        'between' => 'entre',
    ];
    $valueTypeLabels = [
        'string' => 'texto',
        'int' => 'número',
        'bool' => 'sí/no',
        'date' => 'fecha',
        'datetime' => 'fecha y hora',
        'enum' => 'lista',
        'array' => 'varios valores',
    ];
    $fieldGroups = [
        'Prospectos' => [
            'source_id' => 'Origen del prospecto',
            'status_id' => 'Estado del prospecto',
            'interest_level' => 'Nivel de interés',
            'owner_id' => 'Responsable asignado',
            'person_type' => 'Tipo de persona',
            'company_name' => 'Empresa',
            'email' => 'Correo del prospecto',
            'phone' => 'Teléfono del prospecto',
            'ubigeo_code' => 'Ubicación / distrito',
            'entered_at' => 'Fecha de ingreso',
        ],
        'Oportunidades' => [
            'stage_id' => 'Etapa de oportunidad',
            'estimated_amount' => 'Monto estimado',
            'currency_code' => 'Moneda',
            'priority' => 'Prioridad',
            'lead_id' => 'Prospecto relacionado',
            'customer_id' => 'Cliente relacionado',
        ],
        'Cotizaciones' => [
            'status' => 'Estado de cotización / actividad',
            'total' => 'Total de cotización',
            'expires_at' => 'Fecha de vencimiento',
            'opportunity_id' => 'Oportunidad relacionada',
            'number' => 'Número de cotización',
        ],
        'Actividades' => [
            'title' => 'Título de actividad',
            'scheduled_at' => 'Fecha programada',
            'days_overdue' => 'Días de atraso',
            'type_id' => 'Tipo de actividad',
            'subject_type' => 'Tipo de registro relacionado',
        ],
    ];
    $fieldLabels = [];
    foreach ($fieldGroups as $fields) {
        $fieldLabels = array_merge($fieldLabels, $fields);
    }
    $valuePresets = [
        'interest_level' => [
            'bajo' => 'Bajo',
            'medio' => 'Medio',
            'alto' => 'Alto',
        ],
        'person_type' => [
            'natural' => 'Persona natural',
            'juridica' => 'Empresa / persona jurídica',
        ],
        'priority' => [
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
        ],
        'currency_code' => [
            'PEN' => 'Soles (PEN)',
            'USD' => 'Dólares (USD)',
            'EUR' => 'Euros (EUR)',
        ],
        'status' => [
            'draft' => 'Cotización: borrador',
            'sent' => 'Cotización: enviada',
            'accepted' => 'Cotización: aceptada',
            'rejected' => 'Cotización: rechazada',
            'expired' => 'Cotización: vencida',
            'voided' => 'Cotización: anulada',
            'pending' => 'Actividad: pendiente',
            'in_process' => 'Actividad: en proceso',
            'completed' => 'Actividad: completada',
            'cancelled' => 'Actividad: cancelada',
            'overdue' => 'Actividad: vencida',
        ],
        'subject_type' => [
            \App\Models\Lead::class => 'Prospecto',
            \App\Models\Customer::class => 'Cliente',
            \App\Models\Opportunity::class => 'Oportunidad',
        ],
    ];
    $catalogValueOptions = $catalogValueOptions ?? [];
    $dateFields = ['entered_at', 'expires_at'];
    $dateTimeFields = ['scheduled_at'];
    $numberFields = ['estimated_amount', 'total', 'days_overdue', 'lead_id', 'customer_id', 'opportunity_id'];
    $groupLabel = $logicalOperatorLabels[$logicalOperator] ?? $logicalOperator;
    $groupHelp = $logicalOperatorHelp[$logicalOperator] ?? 'Definí cómo se evalúan las condiciones de este bloque.';
@endphp

<div class="automation-condition-group" wire:key="group-{{ $groupIndex }}">
    <input type="hidden" name="groups[{{ $groupIndex }}][logical_operator]" value="{{ $logicalOperator }}">
    <input type="hidden" name="groups[{{ $groupIndex }}][position]" value="{{ $groupIndex + 1 }}">

    <div class="automation-condition-group-header">
        <div class="d-flex align-items-start gap-2 min-w-0">
            <span class="automation-condition-badge"><i class="bi bi-filter-circle" aria-hidden="true"></i></span>
            <div class="min-w-0">
                <p class="automation-eyebrow mb-1">Bloque {{ $groupIndex + 1 }}</p>
                <strong class="d-block">{{ $groupLabel }}</strong>
                <div class="text-secondary small">{{ $groupHelp }}</div>
            </div>
        </div>
        <div class="automation-logical-toggle" role="group" aria-label="Cómo combinar las condiciones del bloque">
            <button type="button"
                    class="btn btn-sm {{ $logicalOperator === 'AND' ? 'btn-primary' : 'btn-outline-secondary' }}"
                    wire:click="updateLogicalOperator('AND')"
                    wire:loading.attr="disabled"
                    wire:target="updateLogicalOperator('AND')"
                    aria-pressed="{{ $logicalOperator === 'AND' ? 'true' : 'false' }}">
                <span wire:loading.remove wire:target="updateLogicalOperator('AND')">Todas las condiciones</span>
                <span wire:loading wire:target="updateLogicalOperator('AND')">Aplicando…</span>
            </button>
            <button type="button"
                    class="btn btn-sm {{ $logicalOperator === 'OR' ? 'btn-primary' : 'btn-outline-secondary' }}"
                    wire:click="updateLogicalOperator('OR')"
                    wire:loading.attr="disabled"
                    wire:target="updateLogicalOperator('OR')"
                    aria-pressed="{{ $logicalOperator === 'OR' ? 'true' : 'false' }}">
                <span wire:loading.remove wire:target="updateLogicalOperator('OR')">Cualquiera de las condiciones</span>
                <span wire:loading wire:target="updateLogicalOperator('OR')">Aplicando…</span>
            </button>
        </div>
    </div>

    <div class="automation-condition-group-body">
        @forelse ($conditions as $index => $condition)
            <div class="automation-condition-mini-node" wire:key="cond-{{ $groupIndex }}-{{ $index }}">
                <div class="automation-condition-mini-node-header">
                    <span class="automation-condition-index">{{ $index + 1 }}</span>
                    <span class="automation-condition-text">Condición del CRM</span>
                </div>
                    <div class="row g-2 align-items-end automation-condition-row">
                        <div class="col-md-3">
                            @php
                                $currentField = (string) ($condition['field'] ?? '');
                            @endphp
                            <label class="form-label small mb-1" for="group-{{ $groupIndex }}-condition-{{ $index }}-field">Qué dato revisar</label>
                            <select id="group-{{ $groupIndex }}-condition-{{ $index }}-field"
                                    name="groups[{{ $groupIndex }}][conditions][{{ $index }}][field]"
                                    class="form-select form-select-sm"
                                    wire:model.live="conditions.{{ $index }}.field">
                                <option value="">— Elegí el dato del CRM —</option>
                                @foreach ($fieldGroups as $groupName => $fields)
                                    <optgroup label="{{ $groupName }}">
                                        @foreach ($fields as $fieldValue => $fieldLabel)
                                            <option value="{{ $fieldValue }}">{{ $fieldLabel }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                                @if ($currentField !== '' && ! array_key_exists($currentField, $fieldLabels))
                                    <option value="{{ $currentField }}">Dato personalizado guardado: {{ $currentField }}</option>
                                @endif
                            </select>
                            <div class="form-text">Elegí el dato de negocio que el CRM debe evaluar. El sistema guarda el campo interno correcto.</div>
                        </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="group-{{ $groupIndex }}-condition-{{ $index }}-operator">Cómo compararlo</label>
                        <select id="group-{{ $groupIndex }}-condition-{{ $index }}-operator"
                                name="groups[{{ $groupIndex }}][conditions][{{ $index }}][operator]"
                                class="form-select form-select-sm" wire:model.live="conditions.{{ $index }}.operator">
                            @foreach (\App\Enums\ConditionOperator::values() as $op)
                                <option value="{{ $op }}">{{ $operatorLabels[$op] ?? $op }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="group-{{ $groupIndex }}-condition-{{ $index }}-value-type">Tipo de dato</label>
                        <select id="group-{{ $groupIndex }}-condition-{{ $index }}-value-type"
                                name="groups[{{ $groupIndex }}][conditions][{{ $index }}][value_type]"
                                class="form-select form-select-sm" wire:model="conditions.{{ $index }}.value_type">
                            @foreach (['string','int','bool','date','datetime','enum','array'] as $vt)
                                <option value="{{ $vt }}">{{ $valueTypeLabels[$vt] ?? $vt }}</option>
                            @endforeach
                        </select>
                    </div>
                        <div class="col-md-3">
                            @php
                                $currentOperator = (string) ($condition['operator'] ?? '');
                                $currentValue = (string) ($condition['value'] ?? '');
                                $valueOptions = ($catalogValueOptions[$currentField] ?? []) ?: ($valuePresets[$currentField] ?? []);
                                $valueInputType = in_array($currentField, $dateFields, true)
                                    ? 'date'
                                    : (in_array($currentField, $dateTimeFields, true)
                                        ? 'datetime-local'
                                        : (in_array($currentField, $numberFields, true) ? 'number' : 'text'));
                                $valuePlaceholder = match ($currentField) {
                                    'company_name' => 'Ej. Maia Consultores',
                                    'email' => 'Ej. cliente@empresa.com',
                                    'phone' => 'Ej. 999888777',
                                    'ubigeo_code' => 'Ej. 150101',
                                    'number' => 'Ej. COT-000123',
                                    'title' => 'Ej. Llamar al cliente',
                                    'days_overdue' => 'Ej. 3',
                                    'estimated_amount', 'total' => 'Ej. 1500',
                                    default => 'Elegí o escribí el valor',
                                };
                                $operatorDoesNotNeedValue = in_array($currentOperator, ['is_null', 'is_not_null'], true);
                            @endphp
                            <label class="form-label small mb-1" for="group-{{ $groupIndex }}-condition-{{ $index }}-value">Valor a buscar</label>
                            @if ($operatorDoesNotNeedValue)
                                <input type="hidden" name="groups[{{ $groupIndex }}][conditions][{{ $index }}][value]" value="">
                                <input type="text"
                                       id="group-{{ $groupIndex }}-condition-{{ $index }}-value"
                                       class="form-control form-control-sm"
                                       value="No hace falta completar un valor"
                                       disabled>
                                <div class="form-text">Esta comparación sólo revisa si el dato existe o está vacío.</div>
                            @elseif ($valueOptions !== [])
                                <select id="group-{{ $groupIndex }}-condition-{{ $index }}-value"
                                        name="groups[{{ $groupIndex }}][conditions][{{ $index }}][value]"
                                        class="form-select form-select-sm"
                                        wire:model="conditions.{{ $index }}.value">
                                    <option value="">— Elegí el valor —</option>
                                    @foreach ($valueOptions as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                    @endforeach
                                    @if ($currentValue !== '' && ! array_key_exists($currentValue, $valueOptions))
                                        <option value="{{ $currentValue }}">Valor personalizado guardado: {{ $currentValue }}</option>
                                    @endif
                                </select>
                                <div class="form-text">Opciones disponibles para el dato seleccionado.</div>
                            @else
                                <input type="{{ $valueInputType }}"
                                       id="group-{{ $groupIndex }}-condition-{{ $index }}-value"
                                       name="groups[{{ $groupIndex }}][conditions][{{ $index }}][value]"
                                       class="form-control form-control-sm"
                                       wire:model="conditions.{{ $index }}.value" autocomplete="off"
                                       placeholder="{{ $valuePlaceholder }}">
                                <div class="form-text">Para “entre” o “varios valores”, separá opciones con coma.</div>
                            @endif
                        </div>
                    <input type="hidden" name="groups[{{ $groupIndex }}][conditions][{{ $index }}][position]" value="{{ $condition['position'] ?? ($index + 1) }}">
                    <div class="col-md-1">
                        <button type="button" class="btn btn-sm btn-outline-danger w-100"
                                wire:click="removeCondition({{ $index }})"
                                wire:loading.attr="disabled"
                                wire:target="removeCondition({{ $index }})"
                                aria-label="Quitar condición {{ $index + 1 }}" title="Quitar condición">
                            <span wire:loading.remove wire:target="removeCondition({{ $index }})">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </span>
                            <span wire:loading wire:target="removeCondition({{ $index }})">
                                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                <span class="visually-hidden">Quitando…</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="automation-empty-state small mb-3">
                <strong>Sin condiciones todavía.</strong>
                <span>Agregá una fila para definir cuándo aplica esta regla. Ejemplo: origen es igual a Web.</span>
            </div>
        @endforelse
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    wire:click="addCondition"
                    wire:loading.attr="disabled"
                    wire:target="addCondition"
                    aria-label="Agregar condición al bloque {{ $groupIndex + 1 }}">
                <span wire:loading.remove wire:target="addCondition">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar condición
                </span>
                <span wire:loading wire:target="addCondition">
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Agregando…
                </span>
            </button>
    </div>
</div>
