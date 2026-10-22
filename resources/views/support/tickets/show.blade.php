@extends('layouts.app')

@section('title', $ticket->code)
@section('page-title', 'Ticket '.$ticket->code)

@php
    $S = \App\Models\SupportStatus::class;
    $Observation = \App\Models\SupportObservation::class;
    $statusSlug = $ticket->status?->slug;
    $user = auth()->user();
    $holdStates = \App\Services\SupportTicketLifecycleService::HOLD_STATES;
    $isOnHold = in_array($statusSlug, $holdStates, true);
    $isOpen = ! in_array($statusSlug, [$S::SLUG_RESOLVED, $S::SLUG_CLOSED, $S::SLUG_CANCELLED], true);
    $isFrozen = in_array($statusSlug, [$S::SLUG_CLOSED, $S::SLUG_CANCELLED], true);
    // A new unassigned ticket is assigned to the actor before starting/scheduling (see controller).
    $isNewUnassigned = $statusSlug === $S::SLUG_NEW && $ticket->responsible_id === null;
    $canStartHere = ! $isOnHold && (in_array($S::SLUG_IN_PROGRESS, $allowed, true) || $isNewUnassigned);
    $holdOptions = array_filter($holdStates, fn ($to) => in_array($to, $allowed, true));
    $canScheduleHere = in_array($statusSlug, [$S::SLUG_NEW, $S::SLUG_ASSIGNED, $S::SLUG_SCHEDULED, $S::SLUG_IN_PROGRESS, $S::SLUG_WAITING_CUSTOMER, $S::SLUG_WAITING_INTERNAL, $S::SLUG_REOPENED], true);
    $unfinishedObservations = $ticket->observations->whereNotIn('state', $Observation::FINISHED_STATES)->count();
    $unattendedObservations = $ticket->observations->whereIn('state', $Observation::UNATTENDED_STATES)->count();
    $isIncident = $ticket->type?->slug === 'incidente-o-error';
    $statusBadge = match ($statusSlug) {
        $S::SLUG_NEW, $S::SLUG_REOPENED => 'text-bg-info',
        $S::SLUG_IN_PROGRESS => 'text-bg-primary',
        $S::SLUG_WAITING_CUSTOMER, $S::SLUG_WAITING_INTERNAL => 'text-bg-warning',
        $S::SLUG_RESOLVED => 'text-bg-success',
        $S::SLUG_CLOSED => 'text-bg-dark',
        $S::SLUG_CANCELLED => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
    $observationActions = ['in_process' => 'Iniciar', 'lifted' => 'Levantar', 'validated' => 'Validar', 'rejected' => 'Rechazar', 'reopened' => 'Reabrir', 'not_applicable' => 'No aplica'];
    $reasonRequired = ['rejected', 'reopened', 'not_applicable'];
    $hasActionOutcome = filled($ticket->solution_summary)
        || filled($ticket->close_reason)
        || filled($ticket->reopen_reason)
        || filled($ticket->cancel_reason);
@endphp

@section('content')
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <div class="text-muted small">{{ $ticket->code }} · {{ $ticket->type?->name }}</div>
            <h2 class="h5 mb-2">{{ $ticket->title }}</h2>
            <div class="d-flex flex-wrap gap-2 align-items-center small">
                <span class="badge {{ $statusBadge }}">{{ $ticket->status?->name }}</span>
                <span class="badge text-bg-light border" @if ($ticket->priority?->color) style="border-color: {{ $ticket->priority->color }} !important" @endif>Prioridad: {{ $ticket->priority?->name ?? '—' }}</span>
                <span><i class="bi bi-person" aria-hidden="true"></i> {{ $ticket->responsible?->name ?? 'Sin asignar' }}</span>
                <span><i class="bi bi-building" aria-hidden="true"></i> {{ $ticket->customer?->trade_name ?: $ticket->customer?->legal_name ?: $ticket->customer?->code }}</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            @if (! $isFrozen)
                @can('update', $ticket)
                    <a href="{{ route('support.tickets.edit', $ticket) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil" aria-hidden="true"></i> Editar ticket</a>
                @endcan
            @endif
            <a href="{{ route('support.tickets.index') }}" class="btn btn-sm btn-outline-secondary">Volver</a>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="h6 mb-0">Detalle</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Cliente</dt>
                    <dd class="col-sm-9">{{ $ticket->customer?->trade_name ?: $ticket->customer?->legal_name ?: $ticket->customer?->code }}</dd>

                    <dt class="col-sm-3">Contacto solicitante</dt>
                    <dd class="col-sm-9">
                        {{ trim(($ticket->requester?->first_name ?? '').' '.($ticket->requester?->last_name ?? '')) ?: '—' }}
                        @if ($ticket->requester?->email)
                            <span class="text-muted">· {{ $ticket->requester->email }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-3">Categoría / canal</dt>
                    <dd class="col-sm-9">{{ $ticket->category?->name ?? '—' }} · {{ $ticket->channel?->name ?? '—' }}</dd>

                    <dt class="col-sm-3">Responsable</dt>
                    <dd class="col-sm-9">{{ $ticket->responsible?->name ?? 'Sin asignar' }}</dd>

                    <dt class="col-sm-3">Equipo</dt>
                    <dd class="col-sm-9">{{ $ticket->team?->name ?? 'Sin equipo' }}</dd>

                    <dt class="col-sm-3">Descripción</dt>
                    <dd class="col-sm-9">{!! nl2br(e($ticket->description)) !!}</dd>

                    @if (filled($ticket->impact))
                        <dt class="col-sm-3">Impacto</dt>
                        <dd class="col-sm-9">{{ $ticket->impact }}</dd>
                    @endif

                    @if (filled($ticket->general_observations))
                        <dt class="col-sm-3">Observaciones generales</dt>
                        <dd class="col-sm-9">{!! nl2br(e($ticket->general_observations)) !!}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0">Registro de acciones</h3>
                <span class="badge {{ $statusBadge }}">{{ $ticket->status?->name }}</span>
            </div>
            <div class="card-body">
                @if ($hasActionOutcome)
                    <dl class="row mb-0 small">
                        @if (filled($ticket->solution_summary))
                            <dt class="col-sm-4">Resumen de solución</dt>
                            <dd class="col-sm-8">
                                @if ($ticket->resolved_at)
                                    <div class="text-muted mb-1">Resuelto el {{ $ticket->resolved_at->format('d/m/Y H:i') }}</div>
                                @endif
                                {!! nl2br(e($ticket->solution_summary)) !!}
                            </dd>
                        @endif

                        @if (filled($ticket->close_reason))
                            <dt class="col-sm-4">Validación de cierre</dt>
                            <dd class="col-sm-8">
                                @if ($ticket->closed_at)
                                    <div class="text-muted mb-1">Cerrado el {{ $ticket->closed_at->format('d/m/Y H:i') }}@if ($ticket->validated_at) · validado por el cliente @endif</div>
                                @endif
                                {!! nl2br(e($ticket->close_reason)) !!}
                            </dd>
                        @endif

                        @if (filled($ticket->reopen_reason))
                            <dt class="col-sm-4">Motivo de reapertura</dt>
                            <dd class="col-sm-8">{!! nl2br(e($ticket->reopen_reason)) !!}</dd>
                        @endif

                        @if (filled($ticket->cancel_reason))
                            <dt class="col-sm-4">Motivo de cancelación</dt>
                            <dd class="col-sm-8">{!! nl2br(e($ticket->cancel_reason)) !!}</dd>
                        @endif
                    </dl>
                @else
                    <p class="text-muted mb-0 small">Cuando resuelva, cierre, reabra o cancele el ticket, el texto ingresado quedará visible aquí.</p>
                @endif
            </div>
        </div>

        @include('documents.partials._panel', ['subject' => $ticket, 'documents' => $ticket->documents])

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Actualizaciones</h3></div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    @can('addUpdate', $ticket)
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('support.tickets.notes.store', $ticket) }}" data-swal-loading>
                                @csrf
                                <textarea required name="body" class="form-control mb-1" placeholder="Nota interna"></textarea>
                                <button class="btn btn-sm btn-outline-secondary" data-swal-confirm data-swal-title="Agregar nota interna" data-swal-text="Se registrará una actualización interna en el ticket." data-swal-type="question">Agregar nota</button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('support.tickets.responses.store', $ticket) }}" data-swal-loading>
                                @csrf
                                <textarea required name="body" class="form-control mb-1" placeholder="Respuesta al cliente"></textarea>
                                <button class="btn btn-sm btn-outline-primary" data-swal-confirm data-swal-title="Registrar respuesta" data-swal-text="Se registrará una respuesta visible para el cliente." data-swal-type="question">Registrar respuesta</button>
                            </form>
                        </div>
                    @endcan
                </div>
                @forelse($ticket->updates as $update)
                    <div class="border-top py-2 small">
                        <strong>{{ $update->is_internal ? 'Nota interna' : 'Respuesta al cliente' }}</strong> · {{ $update->author?->name }}<br>
                        {{ $update->body }}
                    </div>
                @empty
                    <p class="text-muted mb-0">Sin actualizaciones.</p>
                @endforelse
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0">Observaciones</h3>
                @if ($unfinishedObservations > 0)
                    <span class="badge text-bg-warning">{{ $unfinishedObservations }} sin finalizar</span>
                @endif
            </div>
            <div class="card-body">
                @if (! $isFrozen)
                    @can('support.observations.create')
                        <form class="row g-2 mb-3" method="POST" action="{{ route('support.tickets.observations.store', $ticket) }}" data-swal-loading>
                            @csrf
                            <div class="col-md-6"><input name="title" required maxlength="255" class="form-control form-control-sm" placeholder="Nueva observación"></div>
                            <div class="col-md-6"><input name="description" class="form-control form-control-sm" placeholder="Detalle (opcional)"></div>
                            <div class="col-md-5">
                                <select name="responsible_id" class="form-select form-select-sm" aria-label="Responsable de la observación">
                                    <option value="">Sin responsable</option>
                                    @foreach ($users as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4"><input type="date" name="due_at" class="form-control form-control-sm" aria-label="Fecha límite" title="Fecha límite"></div>
                            <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100" data-swal-confirm data-swal-title="Agregar observación" data-swal-text="Se creará una nueva observación para este ticket." data-swal-type="question">Agregar</button></div>
                        </form>
                    @endcan
                @endif

                @forelse($ticket->observations as $observation)
                    @php
                        $targets = array_values(array_filter($observation->allowedTransitions(), fn ($target) => $user?->can($Observation::TARGET_PERMISSIONS[$target] ?? '')));
                    @endphp
                    <div class="border-top py-2">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <strong>{{ $observation->title }}</strong>
                                <span class="badge text-bg-light border">{{ $observation->stateLabel() }}</span>
                                @if (filled($observation->description))
                                    <div class="small text-muted">{{ $observation->description }}</div>
                                @endif
                                <div class="small text-muted">
                                    @if ($observation->responsible_id)
                                        Responsable: {{ $userNames[$observation->responsible_id] ?? '—' }}
                                    @endif
                                    @if ($observation->due_at)
                                        · Vence: {{ $observation->due_at->format('d/m/Y') }}
                                    @endif
                                    @if (filled($observation->reason))
                                        · Motivo: {{ $observation->reason }}
                                    @endif
                                </div>
                            </div>
                            @if (! $isFrozen && $targets !== [])
                                <div class="d-flex flex-wrap gap-1 align-items-start">
                                    @foreach ($targets as $target)
                                        <form method="POST" class="d-flex gap-1" action="{{ route('support.tickets.observations.transition', [$ticket, $observation]) }}" data-swal-loading>
                                            @csrf
                                            <input type="hidden" name="state" value="{{ $target }}">
                                            @if (in_array($target, $reasonRequired, true))
                                                <input name="reason" required maxlength="10000" class="form-control form-control-sm" placeholder="Motivo ({{ mb_strtolower($Observation::LABELS[$target]) }})">
                                            @endif
                                            <button class="btn btn-sm btn-outline-secondary text-nowrap" data-observation-target="{{ $target }}" data-swal-confirm data-swal-title="{{ $observationActions[$target] ?? $Observation::LABELS[$target] }} observación" data-swal-text="La observación pasará a «{{ $Observation::LABELS[$target] }}»." data-swal-type="question">{{ $observationActions[$target] ?? $Observation::LABELS[$target] }}</button>
                                        </form>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        @include('documents.partials._panel', ['subject' => $observation, 'documents' => $observation->documents])
                    </div>
                @empty
                    <p class="text-muted mb-0">No hay observaciones registradas.</p>
                @endforelse
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Historial</h3></div>
            <div class="card-body small">
                <h4 class="h6">Asignaciones</h4>
                @forelse ($ticket->assignments as $assignment)
                    <div class="border-top py-1">
                        <span class="text-muted">{{ $assignment->assigned_at?->format('d/m/Y H:i') }}</span>
                        · {{ $userNames[$assignment->previous_responsible_id] ?? 'Sin responsable' }} → <strong>{{ $userNames[$assignment->new_responsible_id] ?? '—' }}</strong>
                        @if ($assignment->previous_team_id !== $assignment->new_team_id)
                            · Equipo: {{ $teamNames[$assignment->previous_team_id] ?? 'Sin equipo' }} → {{ $teamNames[$assignment->new_team_id] ?? 'Sin equipo' }}
                        @endif
                        @if ($assignment->assigned_by)
                            · por {{ $userNames[$assignment->assigned_by] ?? '—' }}
                        @endif
                        @if (filled($assignment->reason))
                            <div class="text-muted">Motivo: {{ $assignment->reason }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted">Sin asignaciones registradas.</p>
                @endforelse

                <h4 class="h6 mt-3">Reprogramaciones</h4>
                @forelse ($reschedules as $reschedule)
                    <div class="border-top py-1">
                        <span class="text-muted">{{ $reschedule->created_at?->format('d/m/Y H:i') }}</span>
                        · {{ $reschedule->activity?->title ?? 'Actividad' }}:
                        {{ $reschedule->old_scheduled_at?->format('d/m/Y H:i') ?? '—' }} → <strong>{{ $reschedule->new_scheduled_at?->format('d/m/Y H:i') }}</strong>
                        @if ($reschedule->rescheduled_by)
                            · por {{ $userNames[$reschedule->rescheduled_by] ?? '—' }}
                        @endif
                        <div class="text-muted">Motivo: {{ $reschedule->reason }}</div>
                    </div>
                @empty
                    <p class="text-muted">Sin reprogramaciones.</p>
                @endforelse

                <h4 class="h6 mt-3">Estados</h4>
                @forelse ($ticket->statusPeriods as $period)
                    <div class="border-top py-1">
                        <strong>{{ $statusNames[$period->status_id] ?? $period->period_type }}</strong>
                        · {{ $period->started_at?->format('d/m/Y H:i') }} – {{ $period->ended_at?->format('d/m/Y H:i') ?? 'en curso' }}
                        @if ($period->pauses_clock)
                            <span class="badge text-bg-light border">pausa el tiempo efectivo</span>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">Sin cambios de estado registrados.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="h6 mb-0">Responsable</h3></div>
            <div class="card-body">
                @if (! $isOpen)
                    <p class="text-muted mb-0">El ticket {{ mb_strtolower($ticket->status?->name ?? '') }} no admite cambios de responsable.</p>
                @elseif ($user?->can($ticket->responsible_id ? 'reassign' : 'assign', $ticket))
                    <form method="POST" action="{{ route('support.tickets.assign', $ticket) }}" class="vstack gap-2" data-swal-loading>
                        @csrf
                        <select required name="responsible_id" class="form-select">
                            <option value="">Seleccione responsable</option>
                            @foreach ($users as $option)
                                <option value="{{ $option->id }}" @selected($ticket->responsible_id === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <select name="team_id" class="form-select">
                            <option value="">Sin equipo</option>
                            @foreach ($teams as $team)
                                <option value="{{ $team->id }}" @selected($ticket->team_id === $team->id)>{{ $team->name }}</option>
                            @endforeach
                        </select>
                        <input name="reason" value="{{ old('reason') }}" @required($ticket->responsible_id !== null) class="form-control @error('reason') is-invalid @enderror" placeholder="{{ $ticket->responsible_id ? 'Motivo de reasignación (obligatorio)' : 'Motivo de asignación (opcional)' }}">
                        @error('reason')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <button class="btn btn-outline-primary" data-swal-confirm data-swal-title="{{ $ticket->responsible_id ? 'Reasignar responsable' : 'Asignar responsable' }}" data-swal-text="Se actualizará el responsable del ticket." data-swal-type="question">{{ $ticket->responsible_id ? 'Reasignar' : 'Asignar' }}</button>
                    </form>
                @else
                    <p class="text-muted mb-0">No tiene permiso para asignar este ticket.</p>
                @endif
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Acciones</h3></div>
            <div class="card-body d-flex flex-column gap-3">
                @can('support.attention.start')
                    @if ($statusSlug === $S::SLUG_IN_PROGRESS)
                        <button class="btn btn-primary w-100" disabled>Atención en marcha</button>
                    @elseif ($canStartHere)
                        <form method="POST" action="{{ route('support.tickets.start', $ticket) }}" data-swal-loading>
                            @csrf
                            <button class="btn btn-outline-primary w-100" data-swal-confirm data-swal-title="Iniciar atención" data-swal-text="El ticket pasará a atención en marcha." data-swal-type="question">Iniciar atención</button>
                        </form>
                    @endif

                    @if ($isOnHold)
                        <form method="POST" action="{{ route('support.tickets.resume', $ticket) }}" data-swal-loading>
                            @csrf
                            <button class="btn btn-primary w-100" data-swal-confirm data-swal-title="Reanudar atención" data-swal-text="El ticket volverá a atención en marcha." data-swal-type="question">Reanudar atención</button>
                        </form>
                    @elseif ($holdOptions !== [])
                        <form method="POST" action="{{ route('support.tickets.hold', $ticket) }}" class="vstack gap-1" data-swal-loading>
                            @csrf
                            <select name="kind" class="form-select form-select-sm" required aria-label="Tipo de espera">
                                @foreach ($holdOptions as $kind => $to)
                                    <option value="{{ $kind }}">{{ $kind === 'cliente' ? 'En espera del cliente' : 'En espera interna' }}</option>
                                @endforeach
                            </select>
                            <input name="reason" maxlength="10000" class="form-control form-control-sm" placeholder="Motivo de la espera (opcional)">
                            <button class="btn btn-outline-warning w-100" data-swal-confirm data-swal-title="Poner en espera" data-swal-text="La espera del cliente pausa el tiempo efectivo; la espera interna no." data-swal-type="question">Poner en espera</button>
                        </form>
                    @endif
                @endcan

                @if (in_array($S::SLUG_RESOLVED, $allowed, true))
                    @can('support.resolve')
                        <form method="POST" action="{{ route('support.tickets.resolve', $ticket) }}" data-swal-loading>
                            @csrf
                            <textarea required name="solution_summary" class="form-control mb-1" placeholder="Resumen de solución (obligatorio)"></textarea>
                            @if ($unattendedObservations > 0)
                                <div class="form-text text-warning mb-1">Hay {{ $unattendedObservations }} observación(es) por atender: levántelas o márquelas como «No aplica» antes de resolver.</div>
                            @endif
                            <button class="btn btn-success w-100" data-swal-confirm data-swal-title="Resolver ticket" data-swal-text="El ticket quedará marcado como resuelto." data-swal-type="question">Resolver</button>
                        </form>
                    @endcan
                @endif

                @if (in_array($S::SLUG_CLOSED, $allowed, true))
                    @can('support.close')
                        <form method="POST" action="{{ route('support.tickets.close', $ticket) }}" data-swal-loading>
                            @csrf
                            <input required name="reason" class="form-control mb-1" placeholder="Validación o motivo de cierre">
                            <div class="form-check small">
                                <input type="hidden" name="customer_validated" value="0">
                                <input class="form-check-input" type="checkbox" name="customer_validated" value="1" id="customer_validated">
                                <label class="form-check-label" for="customer_validated">Cliente validó la solución</label>
                            </div>
                            @if ($unfinishedObservations > 0)
                                @can('support.close.with-pending-observations')
                                    <div class="form-check small">
                                        <input type="hidden" name="close_pending_exception" value="0">
                                        <input class="form-check-input" type="checkbox" name="close_pending_exception" value="1" id="close_pending_exception">
                                        <label class="form-check-label" for="close_pending_exception">Cerrar con observaciones pendientes ({{ $unfinishedObservations }})</label>
                                    </div>
                                @else
                                    <div class="form-text text-warning">Hay {{ $unfinishedObservations }} observación(es) sin validar; deben finalizarse antes de cerrar.</div>
                                @endcan
                            @endif
                            <button class="btn btn-dark w-100 mt-1" data-swal-confirm data-swal-title="Cerrar ticket" data-swal-text="El ticket quedará cerrado." data-swal-type="warning">Cerrar</button>
                        </form>
                    @endcan
                @endif

                @if (in_array($S::SLUG_REOPENED, $allowed, true))
                    @can('support.reopen')
                        <form method="POST" action="{{ route('support.tickets.reopen', $ticket) }}" data-swal-loading>
                            @csrf
                            <input required name="reason" class="form-control mb-1" placeholder="Motivo de reapertura (qué sigue fallando)">
                            <button class="btn btn-warning w-100" data-swal-confirm data-swal-title="Reabrir ticket" data-swal-text="El ticket volverá al ciclo de atención con un nuevo ciclo." data-swal-type="warning">Reabrir</button>
                        </form>
                    @endcan
                @endif

                @if (in_array($S::SLUG_CANCELLED, $allowed, true))
                    @can('cancel', $ticket)
                        <form method="POST" action="{{ route('support.tickets.cancel', $ticket) }}" data-swal-loading>
                            @csrf
                            <input required name="reason" class="form-control mb-1" placeholder="Motivo de cancelación">
                            <button class="btn btn-outline-danger w-100" data-swal-confirm data-swal-title="Cancelar ticket" data-swal-text="Esta acción cancelará el ticket y no podrá reabrirse." data-swal-type="warning">Cancelar ticket</button>
                        </form>
                    @endcan
                @endif

                @if ($allowed === [] || ! $user?->canAny(['support.attention.start', 'support.resolve', 'support.close', 'support.reopen', 'support.cancel']))
                    <p class="text-muted small mb-0">No hay acciones disponibles para este ticket.</p>
                @endif
            </div>
        </div>

        @if (! $isFrozen)
            @can('support.priority.update')
                <div class="card mt-3">
                    <div class="card-header"><h3 class="h6 mb-0">Prioridad</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('support.tickets.priority.update', $ticket) }}" class="vstack gap-2" data-swal-loading>
                            @csrf
                            @method('PATCH')
                            <select name="priority_id" class="form-select" required aria-label="Prioridad">
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority->id }}" @selected($ticket->priority_id === $priority->id)>{{ $priority->name }}</option>
                                @endforeach
                            </select>
                            <input name="reason" maxlength="10000" class="form-control" placeholder="Motivo del cambio (opcional)">
                            @error('priority_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <button class="btn btn-outline-primary" data-swal-confirm data-swal-title="Cambiar prioridad" data-swal-text="Se actualizará la prioridad del ticket." data-swal-type="question">Cambiar prioridad</button>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Programación</h3></div>
            <div class="card-body">
                @if ($canScheduleHere)
                    @can('support.schedule')
                        <form method="POST" action="{{ route('support.tickets.schedule', $ticket) }}" class="vstack gap-2" data-swal-loading>
                            @csrf
                            <select required name="type_id" class="form-select">
                                <option value="">Tipo de actividad</option>
                                @foreach($activityTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
                            </select>
                            <input required name="title" class="form-control" placeholder="Título">
                            <input required type="datetime-local" name="scheduled_at" class="form-control">
                            <select required name="modality" class="form-select">
                                <option value="virtual">Virtual</option>
                                <option value="presential">Presencial</option>
                                <option value="phone">Teléfono</option>
                                <option value="not_applicable">No aplica</option>
                            </select>
                            <button class="btn btn-outline-primary" data-swal-confirm data-swal-title="Programar atención" data-swal-text="Se creará una actividad y el ticket quedará programado." data-swal-type="question">Programar</button>
                        </form>
                    @endcan
                @endif
                @forelse($ticket->activities as $activity)
                    <div class="border-top mt-2 pt-2 small">
                        {{ $activity->title }} · {{ $activity->scheduled_at?->format('d/m/Y H:i') }}
                        @if (in_array($activity->status, ['completed', 'cancelled'], true))
                            <span class="badge text-bg-light border">{{ $activity->status === 'completed' ? 'Completada' : 'Cancelada' }}</span>
                        @else
                            @can('support.reschedule')
                                <form method="POST" action="{{ route('support.tickets.reschedule', [$ticket, $activity]) }}" data-swal-loading>
                                    @csrf
                                    <input required type="datetime-local" name="scheduled_at" class="form-control form-control-sm mt-1">
                                    <input required name="reason" class="form-control form-control-sm mt-1" placeholder="Motivo">
                                    <button class="btn btn-sm btn-outline-secondary mt-1" data-swal-confirm data-swal-title="Reprogramar atención" data-swal-text="Se cambiará la fecha de esta actividad." data-swal-type="warning">Reprogramar</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                @empty
                    <p class="text-muted small mb-0 mt-2">Sin actividades programadas.</p>
                @endforelse
            </div>
        </div>

        @if ($isIncident)
            <div class="card mt-3">
                <div class="card-header"><h3 class="h6 mb-0">Incidente</h3></div>
                <div class="card-body">
                    @if (! $isFrozen)
                        @can('support.update')
                            <form method="POST" action="{{ route('support.tickets.incident.store', $ticket) }}" data-swal-loading>
                                @csrf
                                <input name="system" value="{{ old('system', $ticket->incidentDetail?->system) }}" class="form-control mb-1" placeholder="Sistema">
                                <textarea name="actual_result" class="form-control mb-1" placeholder="Resultado obtenido">{{ old('actual_result', $ticket->incidentDetail?->actual_result) }}</textarea>
                                <textarea name="technical_solution" class="form-control mb-1" placeholder="Solución técnica">{{ old('technical_solution', $ticket->incidentDetail?->technical_solution) }}</textarea>
                                <button class="btn btn-outline-secondary" data-swal-confirm data-swal-title="Guardar incidente" data-swal-text="Se actualizará el detalle técnico del incidente." data-swal-type="question">Guardar incidente</button>
                            </form>
                        @endcan
                    @endif
                    @if($ticket->incidentDetail)
                        <dl class="small mb-0 mt-2">
                            @foreach (['system' => 'Sistema', 'actual_result' => 'Resultado obtenido', 'technical_solution' => 'Solución técnica'] as $field => $label)
                                @if (filled($ticket->incidentDetail->{$field}))
                                    <dt>{{ $label }}</dt><dd>{{ $ticket->incidentDetail->{$field} }}</dd>
                                @endif
                            @endforeach
                        </dl>
                        @include('documents.partials._panel', ['subject' => $ticket->incidentDetail, 'documents' => $ticket->incidentDetail->documents])
                    @endif
                </div>
            </div>
        @endif

        @foreach($ticket->sessionDetails as $session)
            <div class="card mt-3">
                <div class="card-header"><h3 class="h6 mb-0">Asistencia: {{ $session->topic ?: $session->modality }}</h3></div>
                <div class="card-body">
                    @can('support.schedule')
                        <form method="POST" action="{{ route('support.tickets.sessions.participants.store', [$ticket, $session]) }}" class="row g-1" data-swal-loading>
                            @csrf
                            <div class="col"><input required name="name" class="form-control form-control-sm" placeholder="Nombre"></div>
                            <div class="col"><input name="email" class="form-control form-control-sm" placeholder="Email"></div>
                            <div class="col-auto"><input type="hidden" name="attended" value="0"><input type="checkbox" name="attended" value="1" checked> asistió</div>
                            <div class="col-auto"><button class="btn btn-sm btn-outline-primary" data-swal-confirm data-swal-title="Registrar participante" data-swal-text="Se agregará el participante a la asistencia." data-swal-type="question">Guardar</button></div>
                        </form>
                    @endcan
                    <ul class="small mb-0 mt-2">
                        @foreach($session->participants as $participant)<li>{{ $participant->name }} {{ $participant->attended ? '✓' : '—' }}</li>@endforeach
                    </ul>
                    @include('documents.partials._panel', ['subject' => $session, 'documents' => $session->documents])
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
