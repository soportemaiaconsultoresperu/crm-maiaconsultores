{{--
    B12-UI / PR 5 — `admin.automations.show` (HIST-01..03, HIST-08 + audit partial).

    Sections:
      1. Rule metadata `<dl class="row">` block (HIST-01 header).
      2. Filter form (HIST-02): status / date_from / date_to / subject_type.
      3. History table (HIST-01): paginated executions with status badges.
      4. Audit contextual block (HIST-08, gated by @can('automations.audit')).

    Expected variables from `AutomationController::show`:
      - $rule           AutomationRule
      - $executions     LengthAwarePaginator<AutomationExecution>
      - $auditEntries   LengthAwarePaginator<Activity>
      - $filters        array<string,string>
--}}
@extends('layouts.app')

@section('title', 'Regla #' . $rule->id)
@section('page-title', 'Regla #' . $rule->id . ' · ' . $rule->name)

@section('content')
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
        $modeLabels = [
            'test' => 'Prueba segura',
            'live' => 'Producción',
        ];
        $subjectLabels = [
            'Lead' => 'Prospecto',
            'Customer' => 'Cliente',
            'Opportunity' => 'Oportunidad',
            'Quotation' => 'Cotización',
            'Activity' => 'Actividad',
            'Contact' => 'Contacto',
        ];
        $humanizeAutomationKey = static function (?string $value): string {
            $base = class_basename($value ?: '');
            $base = str_replace(['_', '-'], ' ', $base);

            return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $base)) ?: 'No definido';
        };
        $triggerBase = class_basename($rule->trigger_event);
        $triggerLabel = $triggerLabels[$triggerBase] ?? $humanizeAutomationKey($rule->trigger_event);
        $modeLabel = $modeLabels[$rule->mode] ?? $humanizeAutomationKey($rule->mode);
        $conditionGroups = $rule->conditionGroups()->with('conditions')->get();
        $ruleActions = $rule->actions()->orderBy('position')->get();
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <a href="{{ route('admin.automations.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver
        </a>
        @can('automations.manage')
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.automations.edit', $rule) }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                </a>
                <form method="POST" action="{{ route('admin.automations.clone', $rule) }}" class="d-inline" data-swal-loading>
                    @csrf
                    <button type="submit"
                            class="btn btn-sm btn-outline-secondary"
                            data-swal-confirm
                            data-swal-title="Duplicar automatización"
                            data-swal-text="Se creará una copia de esta regla para editarla sin tocar la original."
                            data-swal-type="question"
                            data-swal-confirm-text="Sí, duplicar">
                        <i class="bi bi-copy me-1" aria-hidden="true"></i> Duplicar
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.automations.destroy', $rule) }}" class="d-inline" data-swal-loading>
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="btn btn-sm btn-outline-danger"
                            data-swal-confirm
                            data-swal-title="Enviar a papelera"
                            data-swal-text="La automatización «{{ $rule->name }}» dejará de estar disponible hasta que la restaures."
                            data-swal-type="warning"
                            data-swal-confirm-text="Sí, enviar">
                        <i class="bi bi-trash me-1" aria-hidden="true"></i> Papelera
                    </button>
                </form>
            </div>
        @endcan
    </div>

    <div class="card automation-rule-summary mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <p class="text-uppercase text-secondary small mb-1">Resumen de negocio</p>
                    <h2 class="h5 mb-1">{{ $rule->name }}</h2>
                    <p class="text-muted mb-0">{{ $rule->description ?? 'Sin descripción cargada.' }}</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-{{ $rule->isLiveMode() ? 'success' : 'secondary' }}">{{ $modeLabel }}</span>
                    <span class="badge bg-{{ $rule->is_active ? 'success' : 'secondary' }}">
                        {{ $rule->is_active ? 'Habilitada' : 'Pausada' }}
                    </span>
                    <x-test-mode-badge :mode="$rule->mode" extraClass="ms-1" idPrefix="tm-rule-{{ $rule->id }}" />
                </div>
            </div>

            <div class="automation-recipe-grid">
                <div class="automation-recipe-card">
                    <span>CUANDO ocurre</span>
                    <strong>{{ $triggerLabel }}</strong>
                    <small>ID interno: #{{ $rule->id }}</small>
                </div>
                <div class="automation-recipe-card">
                    <span>SI cumple</span>
                    <strong>{{ $conditionGroups->isEmpty() ? 'Sin condiciones configuradas' : $conditionGroups->count() . ' bloque(s) de condiciones' }}</strong>
                    <small>{{ $conditionGroups->isEmpty() ? 'Se ejecuta cada vez que ocurra el evento.' : 'Cada bloque define cuándo aplica la regla.' }}</small>
                </div>
                <div class="automation-recipe-card">
                    <span>ENTONCES hacer</span>
                    <strong>{{ $ruleActions->count() }} acción(es)</strong>
                    <small>Se ejecutan en el orden definido.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" data-testid="rule-conditions-card">
        <div class="card-header">
            <strong>Condiciones configuradas</strong>
        </div>
        <div class="card-body">
            @forelse ($conditionGroups as $group)
                @php
                    $groupLabel = ($group->logical_operator === 'OR')
                        ? 'Cualquiera de estas condiciones'
                        : 'Todas estas condiciones';
                @endphp
                <div class="mb-3">
                    <div class="fw-semibold">Bloque {{ $loop->iteration }} · {{ $groupLabel }}</div>
                    @forelse ($group->conditions as $condition)
                        <div class="small text-muted border-bottom py-1">
                            {{ $humanizeAutomationKey($condition->field) }}
                            {{ $operatorLabels[$condition->operator] ?? $condition->operator }}
                            <strong>{{ $condition->value !== null && $condition->value !== '' ? $condition->value : 'sin valor' }}</strong>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">Este bloque no tiene condiciones cargadas.</p>
                    @endforelse
                </div>
            @empty
                <p class="text-muted small mb-0">Esta regla no tiene condiciones: se revisará cada vez que ocurra el evento inicial.</p>
            @endforelse
        </div>
    </div>

    <div class="card mb-4" data-testid="rule-actions-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Acciones configuradas</strong>
            @can('automations.test')
                <span class="badge text-bg-info">Podés probarla sin afectar datos reales</span>
            @endcan
        </div>
        <div class="card-body">
            @forelse ($ruleActions as $action)
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom py-2">
                    <div>
                        <strong>{{ $actionTypeLabels[$action->type] ?? $humanizeAutomationKey($action->type) }}</strong>
                        <span class="text-muted small ms-2">Orden {{ $action->position }}</span>
                        @if (in_array($action->type, ['send_whatsapp_template', 'webhook'], true))
                            <span class="badge text-bg-warning ms-2">Disponible sólo para prueba segura</span>
                        @endif
                    </div>
                    @can('automations.test')
                        <livewire:admin.automations.simulate-button
                            :ruleId="$rule->id"
                            :actionId="$action->id"
                            :actionType="$action->type"
                            :wire:key="'simulate-action-'.$action->id" />
                    @endcan
                </div>
            @empty
                <p class="text-muted small mb-0">Esta regla todavía no tiene acciones configuradas.</p>
            @endforelse
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filter form (HIST-02) — plain GET so URL stays shareable.          --}}
    {{-- ------------------------------------------------------------------ --}}
    <form method="GET" action="{{ route('admin.automations.show', $rule) }}"
          class="card card-body mb-3" data-testid="history-filter-form" data-swal-loading>
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Resultado</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— Cualquiera —</option>
                    @foreach (\App\Enums\AutomationExecutionStatus::values() as $value)
                        <option value="{{ $value }}"
                                @selected(($filters['status'] ?? '') === $value)>
                            {{ \App\Enums\AutomationExecutionStatus::label($value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Desde</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="{{ $filters['date_from'] ?? '' }}">
            </div>

            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Hasta</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="{{ $filters['date_to'] ?? '' }}">
            </div>

            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Registro relacionado</label>
                <input type="text" name="subject_type" class="form-control form-control-sm"
                       value="{{ $filters['subject_type'] ?? '' }}" placeholder="Ej. Prospecto">
            </div>

            <div class="col-12 d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i> Filtrar
                </button>
                <a href="{{ route('admin.automations.show', $rule) }}"
                   class="btn btn-outline-secondary btn-sm">
                    Limpiar
                </a>
            </div>
        </div>
    </form>

    {{-- ------------------------------------------------------------------ --}}
    {{-- History table (HIST-01, HIST-03 — empty state when paginator=0).   --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($executions->total() === 0)
        @include('layouts.partials.empty-state', [
            'message' => 'Aún no hay ejecuciones registradas para esta regla',
            'hint' => 'Aparecerán acá cuando el CRM detecte el evento inicial y revise si corresponde ejecutar acciones.',
        ])
    @else
        <x-table title="Ejecuciones recientes">
            @slot('headers')
                <tr>
                    <th style="width: 5rem;">ID</th>
                    <th>Registro</th>
                    <th style="width: 9rem;">Resultado</th>
                    <th style="width: 6rem;">Intentos</th>
                    <th style="width: 12rem;">Inicio</th>
                    <th style="width: 12rem;">Fin</th>
                    <th style="width: 7rem;"></th>
                </tr>
            @endslot

            @slot('rows')
                @foreach ($executions as $execution)
                    @php
                        $subjectBase = class_basename($execution->subject_type);
                        $subjectLabel = $subjectLabels[$subjectBase] ?? $humanizeAutomationKey($execution->subject_type);
                    @endphp
                    <tr data-testid="execution-row">
                        <td>
                            <code>#{{ $execution->id }}</code>
                            <x-test-mode-badge :mode="$rule->mode"
                                               extraClass="ms-1"
                                               idPrefix="tm-exec-{{ $execution->id }}" />
                        </td>
                        <td>
                            {{ $subjectLabel }}
                            <span class="text-muted">#{{ $execution->subject_id }}</span>
                        </td>
                        <td>
                            @php
                                $execBadgeMap = [
                                    'queued' => 'text-bg-info',
                                    'running' => 'text-bg-primary',
                                    'success' => 'text-bg-success',
                                    'partial' => 'text-bg-warning',
                                    'failed' => 'text-bg-danger',
                                    'skipped' => 'text-bg-secondary',
                                    'circuit-broken' => 'text-bg-dark',
                                ];
                                $execStatusClass = $execBadgeMap[$execution->status] ?? 'text-bg-secondary';
                                $execStatusLabel = \App\Enums\AutomationExecutionStatus::label($execution->status);
                            @endphp
                            <span class="badge {{ $execStatusClass }}"
                                  data-status="{{ $execution->status }}"
                                  data-testid="execution-status-badge">{{ $execStatusLabel }}</span>
                        </td>
                        <td>{{ $execution->attempt }}</td>
                        <td>
                            <small class="font-monospace">
                                {{ optional($execution->started_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                            </small>
                        </td>
                        <td>
                            <small class="font-monospace">
                                {{ optional($execution->finished_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                            </small>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.automations.executions.show', [$rule, $execution]) }}"
                               class="btn btn-sm btn-outline-primary">
                                Ver
                            </a>
                        </td>
                    </tr>
                @endforeach
            @endslot

            @slot('pagination')
                @include('layouts.partials.pagination', ['paginator' => $executions])
            @endslot
        </x-table>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Audit contextual block (HIST-08, PERM-05, AC-9, SCN-PERM-03).      --}}
    {{-- Without the `automations.audit` permission, this section is        --}}
    {{-- simply NOT rendered — satisfies SCN-HIST-05 / SCN-HIST-01-D.        --}}
    {{-- ------------------------------------------------------------------ --}}
    @can('automations.audit')
        @include('admin.automations.partials._audit_changes', [
            'auditEntries' => $auditEntries,
        ])
    @endcan
@endsection
