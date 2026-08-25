@extends('layouts.app')

@section('title', 'Auditoría — Regla #' . $rule->id)
@section('page-title', 'Auditoría — ' . $rule->name)

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
        $modeLabels = [
            'test' => 'Prueba segura',
            'live' => 'Producción',
        ];
        $humanizeAutomationKey = static function (?string $value): string {
            $base = class_basename($value ?: '');
            $base = str_replace(['_', '-'], ' ', $base);

            return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $base)) ?: 'No definido';
        };
        $triggerBase = class_basename($rule->trigger_event);
        $triggerLabel = $triggerLabels[$triggerBase] ?? $humanizeAutomationKey($rule->trigger_event);
        $modeLabel = $modeLabels[$rule->mode] ?? $humanizeAutomationKey($rule->mode);
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('admin.automations.show', $rule) }}"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Volver a la regla
        </a>
    </div>

    <div class="card card-body mb-4">
        <p class="text-uppercase text-secondary small mb-1">Cambios registrados</p>
        <dl class="row mb-0">
            <dt class="col-sm-2">Regla</dt>
            <dd class="col-sm-10">
                #{{ $rule->id }} · {{ $rule->name }}
                <x-test-mode-badge :mode="$rule->mode" extraClass="ms-1" />
            </dd>

            <dt class="col-sm-2">Se inicia cuando</dt>
            <dd class="col-sm-10">{{ $triggerLabel }}</dd>

            <dt class="col-sm-2">Entorno</dt>
            <dd class="col-sm-10">{{ $modeLabel }}</dd>
        </dl>
    </div>

    {{-- SCN-AUDIT-01-A — dedicated Blade view (not JSON) for the audit feed. --}}
    @include('admin.automations.partials._audit_changes', ['auditEntries' => $entries])
@endsection
