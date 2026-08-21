{{--
    B12-UI / PR 5 — `admin.automations.executions.show` (HIST-04, HIST-05, HIST-06, HIST-09).
    B12.5-POL-02 — cycle-break <details> block (HIST-07).

    Sections:
      1. Execution metadata `<dl class="row">` block.
      2. Idempotency key + mode badge (HIST-05, HIST-06).
      3. Steps table (HIST-04) with `<pre><code>` for response_json
         and `<x-alert type="error">` for failed steps (HIST-09).
      4. Cycle-break <details> block (B12.5-POL-02 / HIST-07) listing
         AutomationCycleBreak rows attached to the rule.

    Expected variables from `AutomationController::showExecution`:
      - $rule       AutomationRule
      - $execution  AutomationExecution (with steps.action eager-loaded)
--}}
@extends('layouts.app')

@section('title', 'Ejecución #' . $execution->id)
@section('page-title', 'Ejecución #' . $execution->id)

@section('content')
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
    <dl class="row mb-4">
        <dt class="col-sm-2">Regla</dt>
        <dd class="col-sm-10">
            <code>#{{ $rule->id }}</code> · {{ $rule->name }}
            <x-test-mode-badge :mode="$rule->mode"
                               extraClass="ms-1"
                               idPrefix="tm-exec-detail-{{ $execution->id }}" />
        </dd>

        <dt class="col-sm-2">Trigger</dt>
        <dd class="col-sm-10"><code>{{ $execution->trigger_event }}</code></dd>

        <dt class="col-sm-2">Estado</dt>
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

        <dt class="col-sm-2">Sujeto</dt>
        <dd class="col-sm-10">
            <code>{{ $execution->subject_type }}</code> #{{ $execution->subject_id }}
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
            <dt class="col-sm-2">Error</dt>
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

    {{-- ------------------------------------------------------------------ --}}
    {{-- Idempotency key copy (HIST-06, UI-07).                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card card-body bg-light mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <strong class="small text-uppercase text-muted">Idempotency key</strong>
            <small class="text-muted">(literal almacenado en la base de datos — solo lectura)</small>
        </div>
        <x-idempotency-key-copy :value="$execution->idempotency_key" />
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Steps table (HIST-04).                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <x-table title="Pasos">
        @slot('headers')
            <tr>
                <th style="width: 5rem;">#</th>
                <th>Acción</th>
                <th style="width: 9rem;">Estado</th>
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
                            <code>{{ $step->action->type }}</code>
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
                            <code>response_json</code>
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
                        Esta ejecución no tiene pasos.
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
        <summary>Cycle breaks ({{ $rule->cycleBreaks->count() }})</summary>
        @forelse ($rule->cycleBreaks as $break)
            <div class="small mt-1" data-testid="cycle-break-row">
                <code>{{ $break->reason }}</code>
                <small class="text-muted">
                    · {{ optional($break->detected_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                </small>
            </div>
        @empty
            <div class="small text-muted mt-1">No hay cycle breaks.</div>
        @endforelse
    </details>
@endsection
