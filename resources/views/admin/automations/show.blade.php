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
    {{-- ------------------------------------------------------------------ --}}
    {{-- Rule metadata header (HIST-01)                                    --}}
    {{-- ------------------------------------------------------------------ --}}
    <dl class="row mb-4">
        <dt class="col-sm-2">ID</dt>
        <dd class="col-sm-10">
            <code>#{{ $rule->id }}</code>
            <x-test-mode-badge :mode="$rule->mode" extraClass="ms-1" idPrefix="tm-rule-{{ $rule->id }}" />
        </dd>

        <dt class="col-sm-2">Nombre</dt>
        <dd class="col-sm-10">{{ $rule->name }}</dd>

        <dt class="col-sm-2">Trigger</dt>
        <dd class="col-sm-10"><code>{{ $rule->trigger_event }}</code></dd>

        <dt class="col-sm-2">Modo</dt>
        <dd class="col-sm-10">{{ $rule->mode }}</dd>

        <dt class="col-sm-2">Activa</dt>
        <dd class="col-sm-10">{{ $rule->is_active ? 'Sí' : 'No' }}</dd>

        <dt class="col-sm-2">Descripción</dt>
        <dd class="col-sm-10">{{ $rule->description ?? '—' }}</dd>
    </dl>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filter form (HIST-02) — plain GET so URL stays shareable.          --}}
    {{-- ------------------------------------------------------------------ --}}
    <form method="GET" action="{{ route('admin.automations.show', $rule) }}"
          class="card card-body mb-3" data-testid="history-filter-form">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Estado</label>
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
                <label class="form-label small text-muted mb-1">Sujeto (tipo)</label>
                <input type="text" name="subject_type" class="form-control form-control-sm"
                       value="{{ $filters['subject_type'] ?? '' }}" placeholder="ej. Lead">
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
            'hint' => 'Las ejecuciones aparecerán aquí cuando el motor procese un evento que coincida con el trigger de esta regla.',
        ])
    @else
        <x-table title="Ejecuciones recientes">
            @slot('headers')
                <tr>
                    <th style="width: 5rem;">ID</th>
                    <th>Sujeto</th>
                    <th style="width: 9rem;">Estado</th>
                    <th style="width: 6rem;">Intentos</th>
                    <th style="width: 12rem;">Inicio</th>
                    <th style="width: 12rem;">Fin</th>
                    <th style="width: 7rem;"></th>
                </tr>
            @endslot

            @slot('rows')
                @foreach ($executions as $execution)
                    <tr data-testid="execution-row">
                        <td>
                            <code>#{{ $execution->id }}</code>
                            <x-test-mode-badge :mode="$rule->mode"
                                               extraClass="ms-1"
                                               idPrefix="tm-exec-{{ $execution->id }}" />
                        </td>
                        <td>
                            <code>{{ $execution->subject_type }}</code>
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
