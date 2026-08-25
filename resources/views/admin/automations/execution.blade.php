{{--
    B12-UI / PR 5 — `admin.automations.executions.show` (HIST-04, HIST-05, HIST-06, HIST-09).
    B12.5-POL-02 — cycle-break <details> block (HIST-07).

    Expected variables from `AutomationController::showExecution`:
      - $rule       AutomationRule
      - $execution  AutomationExecution (with steps.action eager-loaded)
--}}
@extends('layouts.app')

@section('title', 'Ejecución #' . $execution->id)
@section('page-title', 'Ejecución #' . $execution->id)

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
        $triggerBase = class_basename($execution->trigger_event);
        $triggerLabel = $triggerLabels[$triggerBase] ?? $humanizeAutomationKey($execution->trigger_event);
        $subjectBase = class_basename($execution->subject_type);
        $subjectLabel = $subjectLabels[$subjectBase] ?? $humanizeAutomationKey($execution->subject_type);
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('admin.automations.show', $rule) }}"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Volver a la regla
        </a>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Execution metadata (HIST-04 header).                              --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card card-body mb-4">
        <p class="text-uppercase text-secondary small mb-1">Detalle de ejecución</p>
        <dl class="row mb-0">
            <dt class="col-sm-2">Regla</dt>
            <dd class="col-sm-10">
                #{{ $rule->id }} · {{ $rule->name }}
                <x-test-mode-badge :mode="$rule->mode"
                                   extraClass="ms-1"
                                   idPrefix="tm-exec-detail-{{ $execution->id }}" />
            </dd>

            <dt class="col-sm-2">Se inició cuando</dt>
            <dd class="col-sm-10">{{ $triggerLabel }}</dd>

            <dt class="col-sm-2">Resultado</dt>
            <dd class="col-sm-10">
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
            </dd>

            <dt class="col-sm-2">Registro relacionado</dt>
            <dd class="col-sm-10">
                {{ $subjectLabel }} #{{ $execution->subject_id }}
            </dd>

            <dt class="col-sm-2">Inició</dt>
            <dd class="col-sm-10">
                <small class="font-monospace">
                    {{ optional($execution->started_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                </small>
            </dd>

            <dt class="col-sm-2">Finalizó</dt>
            <dd class="col-sm-10">
                <small class="font-monospace">
                    {{ optional($execution->finished_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                </small>
            </dd>

            <dt class="col-sm-2">Intentos</dt>
            <dd class="col-sm-10">{{ $execution->attempt }}</dd>

            @if ($execution->error_class)
                <dt class="col-sm-2">Problema detectado</dt>
                <dd class="col-sm-10">
                    <x-alert type="error" class="mb-0">
                        <strong>{{ $execution->error_class }}</strong>
                        @if ($execution->error_message)
                            <br><small>{{ $execution->error_message }}</small>
                        @endif
                    </x-alert>
                </dd>
            @endif
        </dl>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Idempotency key copy (HIST-06, UI-07).                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card card-body bg-light mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <strong class="small text-uppercase text-muted">Clave única de ejecución</strong>
            <small class="text-muted">(dato interno de solo lectura para soporte)</small>
        </div>
        <x-idempotency-key-copy :value="$execution->idempotency_key" />
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Steps table (HIST-04).                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <x-table title="Acciones realizadas">
        @slot('headers')
            <tr>
                <th style="width: 5rem;">#</th>
                <th>Acción</th>
                <th style="width: 9rem;">Resultado</th>
                <th style="width: 5rem;">Intento</th>
                <th style="width: 12rem;">Inició</th>
                <th style="width: 12rem;">Finalizó</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($execution->steps as $index => $step)
                <tr data-testid="execution-step-row">
                    <td>
                        <code>{{ $index + 1 }}</code>
                    </td>
                    <td>
                        @if ($step->action)
                            {{ $actionTypeLabels[$step->action->type] ?? $humanizeAutomationKey($step->action->type) }}
                            <span class="text-muted small">#{{ $step->action->id }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $stepBadgeMap = [
                                'pending' => 'text-bg-info',
                                'simulated' => 'text-bg-info',
                                'running' => 'text-bg-primary',
                                'success' => 'text-bg-success',
                                'failed' => 'text-bg-danger',
                                'skipped' => 'text-bg-secondary',
                            ];
                            $stepStatusClass = $stepBadgeMap[$step->status] ?? 'text-bg-secondary';
                            $stepStatusLabel = \App\Enums\AutomationStepStatus::label($step->status);
                        @endphp
                        <span class="badge {{ $stepStatusClass }}"
                              data-status="{{ $step->status }}"
                              data-testid="step-status-badge">{{ $stepStatusLabel }}</span>
                    </td>
                    <td>{{ $step->attempt }}</td>
                    <td>
                        <small class="font-monospace">
                            {{ optional($step->started_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                        </small>
                    </td>
                    <td>
                        <small class="font-monospace">
                            {{ optional($step->finished_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                        </small>
                    </td>
                </tr>

                {{-- Expanded response_json row (HIST-04) --}}
                @if (! empty($step->response_json))
                    <tr data-testid="execution-step-response-row">
                        <td colspan="2" class="text-end small text-muted">
                            Respuesta guardada
                        </td>
                        <td colspan="4">
                            <pre class="mb-0 small bg-light p-2 rounded border"
                                 style="max-width: 720px; overflow-x: auto;"><code class="font-monospace">{{ json_encode($step->response_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        </td>
                    </tr>
                @endif

                {{-- Failed-step error alert (HIST-04, HIST-09) --}}
                @if ($step->status === 'failed' && ($step->error_class || $step->error_message))
                    <tr data-testid="execution-step-error-row">
                        <td colspan="6">
                            <x-alert type="error" class="mb-0">
                                <strong>{{ $step->error_class ?? 'Error' }}</strong>
                                @if ($step->error_message)
                                    <br><small>{{ $step->error_message }}</small>
                                @endif
                            </x-alert>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        Esta ejecución no tiene acciones registradas.
                    </td>
                </tr>
            @endforelse
        @endslot
    </x-table>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Cycle-break <details> block (B12.5-POL-02 / HIST-07).             --}}
    {{-- Lazy-loaded via $rule->cycleBreaks (relation on AutomationRule). --}}
    {{-- ------------------------------------------------------------------ --}}
    <details class="mt-4" data-testid="cycle-break-details">
        <summary>Cortes automáticos para evitar bucles ({{ $rule->cycleBreaks->count() }})</summary>
        @forelse ($rule->cycleBreaks as $break)
            <div class="small mt-1" data-testid="cycle-break-row">
                {{ $humanizeAutomationKey($break->reason) }}
                <small class="text-muted">
                    · {{ optional($break->detected_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                </small>
            </div>
        @empty
            <div class="small text-muted mt-1">No se detectaron bucles para esta regla.</div>
        @endforelse
    </details>
@endsection
