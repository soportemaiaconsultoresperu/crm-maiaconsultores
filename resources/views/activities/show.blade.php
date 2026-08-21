@extends('layouts.app')

@section('title', $activity->title)
@section('page-title', 'Actividad')

@section('content')
    @php
        $subjectRoute = null;
        $subjectLabel = '—';
        $subjectMorphKey = null;
        if ($activity->subject) {
            $morph = $activity->subject->getMorphClass();
            $subjectRoute = $subjectRoute ?? match ($morph) {
                'lead' => route('leads.show', $activity->subject),
                'customer' => route('customers.show', $activity->subject),
                'opportunity' => route('opportunities.show', $activity->subject),
                default => null,
            };
            $subjectMorphKey = match (true) {
                $activity->subject instanceof \App\Models\Lead => 'lead',
                $activity->subject instanceof \App\Models\Customer => 'customer',
                $activity->subject instanceof \App\Models\Opportunity => 'opportunity',
                default => null,
            };
            $subjectLabel = match (true) {
                $activity->subject instanceof \App\Models\Lead => $activity->subject->code,
                $activity->subject instanceof \App\Models\Customer => $activity->subject->code,
                $activity->subject instanceof \App\Models\Opportunity => $activity->subject->code,
                default => '#'.$activity->subject->getKey(),
            };
        }

        $isTerminal = in_array($activity->status, ['completed', 'cancelled'], true);
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('update', $activity)
            @if (! $isTerminal)
                <a href="{{ route('activities.edit', $activity) }}" class="btn btn-primary">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
                </a>
            @endif
            @if ($activity->status === 'pending')
                <form method="POST" action="{{ route('activities.start', $activity) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-info" data-testid="btn-start">
                        <i class="bi bi-play me-1" aria-hidden="true"></i> Iniciar
                    </button>
                </form>
            @endif
            @can('activities.complete')
                @if (! $isTerminal)
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#complete-modal" data-testid="btn-complete">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Completar
                    </button>
                @endif
            @endcan
        @endcan
        @if (! $isTerminal)
            <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#cancel-modal" data-testid="btn-cancel">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Cancelar
            </button>
        @endif
        @can('delete', $activity)
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#deactivate-modal">
                <i class="bi bi-archive me-1" aria-hidden="true"></i> Desactivar
            </button>
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Datos de la actividad</h3>
                    <x-badge-status :status="$activity->status"/>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Título</dt>
                        <dd class="col-sm-7">{{ $activity->title }}</dd>

                        <dt class="col-sm-5">Tipo</dt>
                        <dd class="col-sm-7">{{ $activity->type?->name ?? '—' }}</dd>

                        <dt class="col-sm-5">Sujeto</dt>
                        <dd class="col-sm-7">
                            @if ($subjectRoute)
                                <a href="{{ $subjectRoute }}">{{ $subjectLabel }}</a>
                            @else
                                {{ $subjectLabel }}
                            @endif
                        </dd>

                        <dt class="col-sm-5">Estado</dt>
                        <dd class="col-sm-7"><x-badge-status :status="$activity->status"/></dd>

                        <dt class="col-sm-5">Prioridad</dt>
                        <dd class="col-sm-7">{{ $activity->priority ? ucfirst($activity->priority) : '—' }}</dd>

                        <dt class="col-sm-5">Programada</dt>
                        <dd class="col-sm-7">{{ $activity->scheduled_at?->format('d/m/Y H:i') ?? '—' }}</dd>

                        @if ($activity->executed_at)
                            <dt class="col-sm-5">Ejecutada</dt>
                            <dd class="col-sm-7">{{ $activity->executed_at->format('d/m/Y H:i') }}</dd>
                        @endif

                        @if ($activity->reminder_at)
                            <dt class="col-sm-5">Recordatorio</dt>
                            <dd class="col-sm-7">{{ $activity->reminder_at->format('d/m/Y H:i') }}</dd>
                        @endif

                        <dt class="col-sm-5">Responsable</dt>
                        <dd class="col-sm-7">{{ $activity->owner?->name ?? '—' }}</dd>

                        @if ($activity->result)
                            <dt class="col-sm-5">Resultado</dt>
                            <dd class="col-sm-7">{{ $activity->result }}</dd>
                        @endif

                        @if ($activity->description)
                            <dt class="col-sm-5">Descripción</dt>
                            <dd class="col-sm-7">{{ $activity->description }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Sujeto relacionado</h3></div>
                <div class="card-body">
                    @if ($activity->subject)
                        @include('activities.partials._card', ['activity' => $activity])
                    @else
                        @include('layouts.partials.empty-state', ['message' => 'Sujeto no disponible.'])
                    @endif
                </div>
            </div>

@include('documents.partials._panel', [
                'subject' => $activity,
                'documents' => $activity->documents()->orderByDesc('uploaded_at')->orderByDesc('id')->get(),
            ])
        </div>
    </div>

    @can('activities.complete')
        @if (! $isTerminal)
            <x-modal id="complete-modal" title="Completar actividad">
                @include('activities._complete_form', ['activity' => $activity])
            </x-modal>
        @endif
    @endcan

    @if (! $isTerminal)
        <x-swal-confirm
            :action="route('activities.cancel', $activity)"
            method="POST"
            title="¿Cancelar actividad?"
            text="La actividad «{{ $activity->title }}» quedará cancelada. Se conserva el historial."
            type="warning"
            confirm-text="Sí, cancelar"
            input="textarea"
            input-name="reason"
            input-label="Motivo"
            input-required="true"
            input-placeholder="Indique el motivo de la cancelación…"
            button-class="btn-outline-danger">
            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Cancelar
        </x-swal-confirm>
    @endif

    @can('delete', $activity)
        <x-swal-confirm
            :action="route('activities.destroy', $activity)"
            method="DELETE"
            title="¿Desactivar actividad?"
            text="La actividad «{{ $activity->title }}» se desactivará; nunca se elimina físicamente (RNF-DAT-001)."
            type="warning"
            confirm-text="Sí, desactivar"
            input="textarea"
            input-name="reason"
            input-label="Motivo"
            input-required="true"
            input-placeholder="Indique el motivo de la desactivación…"
            button-class="btn-outline-secondary">
            <i class="bi bi-archive me-1" aria-hidden="true"></i> Desactivar
        </x-swal-confirm>
    @endcan
@endsection
