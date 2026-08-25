@extends('layouts.app')

@section('title', $ticket->code)
@section('page-title', 'Ticket '.$ticket->code)

@php
    $statusSlug = $ticket->status?->slug;
    $hasActionOutcome = filled($ticket->solution_summary)
        || filled($ticket->close_reason)
        || filled($ticket->reopen_reason)
        || filled($ticket->cancel_reason);
@endphp

@section('content')
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h2 class="h5 mb-0">{{ $ticket->title }}</h2>
                <span class="badge text-bg-secondary">{{ $ticket->status?->name }}</span>
            </div>
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

                    <dt class="col-sm-3">Responsable</dt>
                    <dd class="col-sm-9">{{ $ticket->responsible?->name ?? 'Sin asignar' }}</dd>

                    <dt class="col-sm-3">Equipo</dt>
                    <dd class="col-sm-9">{{ $ticket->team?->name ?? 'Sin equipo' }}</dd>

                    <dt class="col-sm-3">Descripción</dt>
                    <dd class="col-sm-9">{!! nl2br(e($ticket->description)) !!}</dd>
                </dl>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0">Registro de acciones</h3>
                <span class="badge text-bg-light">{{ $ticket->status?->name }}</span>
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
                                    <div class="text-muted mb-1">Cerrado el {{ $ticket->closed_at->format('d/m/Y H:i') }}</div>
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
                    <p class="text-muted mb-0 small">Cuando resuelvas, cierres, reabras o canceles el ticket, el texto ingresado quedará visible acá.</p>
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
            <div class="card-header"><h3 class="h6 mb-0">Observaciones</h3></div>
            <div class="card-body">
                @can('support.observations.create')
                    <form class="row g-2 mb-3" method="POST" action="{{ route('support.tickets.observations.store', $ticket) }}" data-swal-loading>
                        @csrf
                        <div class="col-md-4"><input name="title" required class="form-control" placeholder="Nueva observación"></div>
                        <div class="col-md-5"><input name="description" class="form-control" placeholder="Detalle"></div>
                        <div class="col-md-3"><button class="btn btn-outline-primary" data-swal-confirm data-swal-title="Agregar observación" data-swal-text="Se creará una nueva observación para este ticket." data-swal-type="question">Agregar</button></div>
                    </form>
                @endcan

                @foreach($ticket->observations as $observation)
                    <div class="border-top py-2">
                        <strong>{{ $observation->title }}</strong> <span class="badge text-bg-light">{{ $observation->state }}</span>
                        <form method="POST" class="d-inline" action="{{ route('support.tickets.observations.transition', [$ticket, $observation]) }}" data-swal-loading>
                            @csrf
                            <select name="state" class="form-select form-select-sm d-inline w-auto">
                                <option value="in_process">En proceso</option>
                                <option value="lifted">Levantada</option>
                                <option value="validated">Validada</option>
                                <option value="rejected">Rechazada</option>
                            </select>
                            <input name="reason" class="form-control form-control-sm d-inline w-auto" placeholder="Motivo si aplica">
                            <button class="btn btn-sm btn-outline-secondary" data-swal-confirm data-swal-title="Cambiar observación" data-swal-text="Se actualizará el estado de la observación." data-swal-type="question">Cambiar</button>
                        </form>
                        @include('documents.partials._panel', ['subject' => $observation, 'documents' => $observation->documents])
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="h6 mb-0">Responsable</h3></div>
            <div class="card-body">
                @if (auth()->user()?->can($ticket->responsible_id ? 'support.reassign' : 'support.assign'))
                    <form method="POST" action="{{ route('support.tickets.assign', $ticket) }}" class="vstack gap-2" data-swal-loading>
                        @csrf
                        <select required name="responsible_id" class="form-select">
                            <option value="">Seleccione responsable</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected($ticket->responsible_id === $user->id)>{{ $user->name }}</option>
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
                    <p class="text-muted mb-0">No tenés permiso para asignar este ticket.</p>
                @endif
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Acciones</h3></div>
            <div class="card-body d-flex flex-column gap-2">
                @can('support.attention.start')
                    @if ($statusSlug === \App\Models\SupportStatus::SLUG_IN_PROGRESS)
                        <button class="btn btn-primary w-100" disabled>Atención en marcha</button>
                    @elseif (! in_array($statusSlug, [\App\Models\SupportStatus::SLUG_RESOLVED, \App\Models\SupportStatus::SLUG_CLOSED, \App\Models\SupportStatus::SLUG_CANCELLED], true))
                        <form method="POST" action="{{ route('support.tickets.start', $ticket) }}" data-swal-loading>
                            @csrf
                            <button class="btn btn-outline-primary w-100" data-swal-confirm data-swal-title="Iniciar atención" data-swal-text="El ticket pasará a atención en marcha." data-swal-type="question">Iniciar atención</button>
                        </form>
                    @endif
                @endcan

                @can('support.resolve')
                    <form method="POST" action="{{ route('support.tickets.resolve', $ticket) }}" data-swal-loading>
                        @csrf
                        <textarea required name="solution_summary" class="form-control mb-1" placeholder="Resumen de solución"></textarea>
                        <button class="btn btn-success w-100" data-swal-confirm data-swal-title="Resolver ticket" data-swal-text="El ticket quedará marcado como resuelto." data-swal-type="question">Resolver</button>
                    </form>
                @endcan

                @can('support.close')
                    <form method="POST" action="{{ route('support.tickets.close', $ticket) }}" data-swal-loading>
                        @csrf
                        <input required name="reason" class="form-control mb-1" placeholder="Validación o motivo">
                        <button class="btn btn-dark w-100" data-swal-confirm data-swal-title="Cerrar ticket" data-swal-text="El ticket quedará cerrado." data-swal-type="warning">Cerrar</button>
                    </form>
                @endcan

                @can('support.reopen')
                    <form method="POST" action="{{ route('support.tickets.reopen', $ticket) }}" data-swal-loading>
                        @csrf
                        <input required name="reason" class="form-control mb-1" placeholder="Motivo de reapertura">
                        <button class="btn btn-warning w-100" data-swal-confirm data-swal-title="Reabrir ticket" data-swal-text="El ticket volverá al ciclo de atención." data-swal-type="warning">Reabrir</button>
                    </form>
                @endcan

                @can('cancel', $ticket)
                    <form method="POST" action="{{ route('support.tickets.cancel', $ticket) }}" data-swal-loading>
                        @csrf
                        <input required name="reason" class="form-control mb-1" placeholder="Motivo de cancelación">
                        <button class="btn btn-outline-danger w-100" data-swal-confirm data-swal-title="Cancelar ticket" data-swal-text="Esta acción cancelará el ticket." data-swal-type="warning">Cancelar</button>
                    </form>
                @endcan
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Programación</h3></div>
            <div class="card-body">
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
                @foreach($ticket->activities as $activity)
                    <div class="border-top mt-2 pt-2 small">
                        {{ $activity->title }} · {{ $activity->scheduled_at?->format('d/m/Y H:i') }}
                        <form method="POST" action="{{ route('support.tickets.reschedule', [$ticket, $activity]) }}" data-swal-loading>
                            @csrf
                            <input required type="datetime-local" name="scheduled_at" class="form-control form-control-sm mt-1">
                            <input required name="reason" class="form-control form-control-sm mt-1" placeholder="Motivo">
                            <button class="btn btn-sm btn-outline-secondary mt-1" data-swal-confirm data-swal-title="Reprogramar atención" data-swal-text="Se cambiará la fecha de esta actividad." data-swal-type="warning">Reprogramar</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="h6 mb-0">Incidente</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('support.tickets.incident.store', $ticket) }}" data-swal-loading>
                    @csrf
                    <input name="system" value="{{ old('system', $ticket->incidentDetail?->system) }}" class="form-control mb-1" placeholder="Sistema">
                    <textarea name="actual_result" class="form-control mb-1" placeholder="Resultado obtenido">{{ old('actual_result', $ticket->incidentDetail?->actual_result) }}</textarea>
                    <textarea name="technical_solution" class="form-control mb-1" placeholder="Solución técnica">{{ old('technical_solution', $ticket->incidentDetail?->technical_solution) }}</textarea>
                    <button class="btn btn-outline-secondary" data-swal-confirm data-swal-title="Guardar incidente" data-swal-text="Se actualizará el detalle técnico del incidente." data-swal-type="question">Guardar incidente</button>
                </form>
                @if($ticket->incidentDetail)
                    @include('documents.partials._panel', ['subject' => $ticket->incidentDetail, 'documents' => $ticket->incidentDetail->documents])
                @endif
            </div>
        </div>

        @foreach($ticket->sessionDetails as $session)
            <div class="card mt-3">
                <div class="card-header"><h3 class="h6 mb-0">Asistencia: {{ $session->topic ?: $session->modality }}</h3></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('support.tickets.sessions.participants.store', [$ticket, $session]) }}" class="row g-1" data-swal-loading>
                        @csrf
                        <div class="col"><input required name="name" class="form-control form-control-sm" placeholder="Nombre"></div>
                        <div class="col"><input name="email" class="form-control form-control-sm" placeholder="Email"></div>
                        <div class="col-auto"><input type="hidden" name="attended" value="0"><input type="checkbox" name="attended" value="1" checked> asistió</div>
                        <div class="col-auto"><button class="btn btn-sm btn-outline-primary" data-swal-confirm data-swal-title="Registrar participante" data-swal-text="Se agregará el participante a la asistencia." data-swal-type="question">Guardar</button></div>
                    </form>
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
