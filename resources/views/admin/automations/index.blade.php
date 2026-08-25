@extends('layouts.app')

@section('title', 'Automatizaciones')
@section('page-title', 'Automatizaciones')

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

            return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $base)) ?: 'Evento no definido';
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <p class="text-uppercase text-secondary small mb-1">Reglas automáticas</p>
            <p class="text-muted mb-1">
                Administrá automatizaciones con lenguaje de negocio: <strong>CUANDO</strong> ocurre un evento,
                <strong>SI</strong> cumple condiciones, <strong>ENTONCES</strong> el CRM realiza acciones.
                @if ($trashView ?? false)
                    Estás viendo la <strong>papelera</strong>; desde acá podés restaurar reglas eliminadas.
                @else
                    Usá “Prueba segura” para validar una regla antes de pasarla a producción.
                @endif
            </p>
        </div>
        @can('automations.manage')
            @unless ($trashView ?? false)
                <a href="{{ route('admin.automations.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>
                    Nueva automatización
                </a>
            @endunless
        @endcan
    </div>

    {{-- Tabs: Activas | Papelera (CRUD-07 / CRUD-08, UI-07) --}}
    @unless ($trashView ?? false)
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('admin.automations.index') }}"
               class="btn btn-sm btn-primary"
               aria-current="page">
                <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>
                En uso
            </a>
            <a href="{{ route('admin.automations.trash') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-trash me-1" aria-hidden="true"></i>
                Papelera
            </a>
        </div>
    @else
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('admin.automations.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>
                En uso
            </a>
            <a href="{{ route('admin.automations.trash') }}"
               class="btn btn-sm btn-warning"
               aria-current="page">
                <i class="bi bi-trash me-1" aria-hidden="true"></i>
                Papelera
            </a>
        </div>
    @endunless

    <x-table title="{{ $trashView ?? false ? 'Automatizaciones en papelera' : 'Automatizaciones guardadas' }}">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Se inicia cuando</th>
                <th>Entorno</th>
                <th>Estado de uso</th>
                <th>Veces ejecutada</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($rules as $rule)
                @php
                    $triggerBase = class_basename($rule->trigger_event);
                    $triggerLabel = $triggerLabels[$triggerBase] ?? $humanizeAutomationKey($rule->trigger_event);
                    $modeLabel = $modeLabels[$rule->mode] ?? $humanizeAutomationKey($rule->mode);
                @endphp
                <tr>
                    <td>{{ $rule->id }}</td>
                    <td>
                        <strong>{{ $rule->name }}</strong>
                        @if ($rule->description)
                            <div class="text-muted small">{{ $rule->description }}</div>
                        @endif
                    </td>
                    <td>{{ $triggerLabel }}</td>
                    <td>
                        <span class="badge bg-{{ $rule->isLiveMode() ? 'success' : 'secondary' }}">
                            {{ $modeLabel }}
                        </span>
                    </td>
                    <td>
                        @if ($trashView ?? false)
                            <span class="badge bg-secondary">En papelera</span>
                        @else
                            @can('automations.manage')
                                <form method="POST"
                                      action="{{ route('admin.automations.toggle', $rule) }}"
                                      class="d-inline"
                                      data-swal-loading>
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="btn btn-sm btn-{{ $rule->is_active ? 'success' : 'outline-secondary' }}"
                                            title="{{ $rule->is_active ? 'Pausar automatización' : 'Habilitar automatización' }}"
                                            aria-label="{{ $rule->is_active ? 'Pausar automatización' : 'Habilitar automatización' }}"
                                            data-swal-confirm
                                            data-swal-title="{{ $rule->is_active ? 'Pausar automatización' : 'Habilitar automatización' }}"
                                            data-swal-text="{{ $rule->is_active ? 'La regla quedará guardada, pero dejará de ejecutarse automáticamente.' : 'La regla volverá a ejecutarse cuando ocurra su evento de inicio.' }}"
                                            data-swal-type="question"
                                            data-swal-confirm-text="Sí, continuar">
                                        <i class="bi {{ $rule->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}" aria-hidden="true"></i>
                                        {{ $rule->is_active ? 'Habilitada' : 'Pausada' }}
                                    </button>
                                </form>
                            @else
                                <span class="badge bg-{{ $rule->is_active ? 'success' : 'secondary' }}">
                                    {{ $rule->is_active ? 'Habilitada' : 'Pausada' }}
                                </span>
                            @endcan
                        @endif
                    </td>
                    <td>{{ $rule->executions_count ?? 0 }}</td>
                    <td class="text-end text-nowrap">
                        @if ($trashView ?? false)
                            @can('automations.manage')
                                <form method="POST"
                                      action="{{ route('admin.automations.restore', ['id' => $rule->id]) }}"
                                      class="d-inline"
                                      data-swal-loading>
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            title="Restaurar automatización"
                                            data-swal-confirm
                                            data-swal-title="Restaurar automatización"
                                            data-swal-text="La automatización volverá al listado de reglas disponibles."
                                            data-swal-type="question"
                                            data-swal-confirm-text="Sí, restaurar">
                                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                                        Restaurar
                                    </button>
                                </form>
                            @endcan
                        @else
                            <a href="{{ route('admin.automations.show', $rule) }}" class="btn btn-sm btn-outline-primary">
                                Ver detalle
                            </a>
                            @can('automations.manage')
                                <a href="{{ route('admin.automations.edit', $rule) }}" class="btn btn-sm btn-outline-secondary">
                                    Editar
                                </a>
                                <form method="POST"
                                      action="{{ route('admin.automations.clone', $rule) }}"
                                      class="d-inline"
                                      data-swal-loading>
                                    @csrf
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-secondary"
                                                data-swal-confirm
                                                data-swal-title="Duplicar automatización"
                                                data-swal-text="Se creará una copia para que puedas ajustarla sin modificar la original."
                                                data-swal-type="question"
                                                data-swal-confirm-text="Sí, duplicar">
                                            Duplicar
                                        </button>
                                </form>
                                <form method="POST"
                                      action="{{ route('admin.automations.destroy', $rule) }}"
                                      class="d-inline"
                                      data-swal-loading>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Enviar a papelera"
                                            data-swal-confirm
                                            data-swal-title="Enviar a papelera"
                                            data-swal-text="La automatización «{{ $rule->name }}» dejará de estar disponible hasta que la restaures."
                                            data-swal-type="warning"
                                            data-swal-confirm-text="Sí, enviar">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                        Papelera
                                    </button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        @if ($trashView ?? false)
                            No hay automatizaciones en la papelera.
                        @else
                            Todavía no hay automatizaciones. Creá la primera con la receta CUANDO / SI / ENTONCES.
                        @endif
                    </td>
                </tr>
            @endforelse

            @if (method_exists($rules, 'links'))
                <tr>
                    <td colspan="7">{{ $rules->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>
@endsection
