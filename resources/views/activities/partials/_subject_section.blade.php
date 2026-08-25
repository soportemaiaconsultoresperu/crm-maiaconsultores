{{--
    Subject-scoped activities panel. Included from lead, customer, and
    opportunity show pages (RF-ACT-006). Receives:
      $activities:  Collection<Activity> (already paginated or a plain
                    collection for the section)
      $subjectType: 'lead' | 'customer' | 'opportunity'
      $subject:     the parent model
      $nextAction:  ?Activity (the next pending future activity)

    The section renders a small "Nueva actividad" button that opens a
    modal with the same form used by the standalone flow, but pre-bound
    to the parent subject.
--}}
@php
        $subjectNoun = match ($subjectType) {
            'lead' => 'el prospecto',
            'customer' => 'el cliente',
            'opportunity' => 'la oportunidad',
            default => 'el sujeto',
        };
        $subjectLabel = \App\Models\Activity::subjectDisplayLabel($subject);

    $createUrl = match ($subjectType) {
        'lead' => route('leads.activities.store', $subject),
        'customer' => route('customers.activities.store', $subject),
        'opportunity' => route('opportunities.activities.store', $subject),
        default => null,
    };
@endphp

<div class="card" data-testid="activities-section">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Actividades</h3>
        @if ($createUrl && auth()->user()->can('create', \App\Models\Activity::class))
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                    data-bs-target="#activity-create-modal-{{ $subjectType }}-{{ $subject->getKey() }}"
                    data-testid="btn-new-activity">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva actividad
            </button>
        @endif
    </div>
    <div class="card-body">
        @if ($nextAction)
            <div class="alert alert-info py-2 mb-3" data-testid="next-action">
                <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                <strong>Próxima acción:</strong>
                {{ $nextAction->title }} — {{ $nextAction->scheduled_at->format('d/m/Y H:i') }}
            </div>
        @else
            <p class="text-secondary small mb-3" data-testid="next-action-empty">Sin próximo seguimiento.</p>
        @endif

        @forelse ($activities as $activity)
            @include('activities.partials._card', ['activity' => $activity])
            @if (! $loop->last)
                <hr>
            @endif
        @empty
            @include('layouts.partials.empty-state', [
                'message' => 'Sin actividades registradas para '.$subjectNoun.'.',
            ])
        @endforelse
    </div>
</div>

@if ($createUrl && auth()->user()->can('create', \App\Models\Activity::class))
    <x-modal id="activity-create-modal-{{ $subjectType }}-{{ $subject->getKey() }}" title="Nueva actividad para {{ $subjectNoun }} {{ $subjectLabel }}">
        <form method="POST" action="{{ $createUrl }}" data-testid="activity-create-form" data-swal-loading>
            @csrf
            <input type="hidden" name="subject_type" value="{{ $subjectType }}">
            <input type="hidden" name="subject_id" value="{{ $subject->getKey() }}">

            <div class="row g-2">
                <div class="col-md-6">
                    <x-select name="type_id" label="Tipo" :required="true" placeholder="Seleccione"
                              :options="\App\Models\ActivityType::query()->where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($t) => [$t->id => $t->name])->all()"
                              :value="old('type_id')"/>
                </div>
                <div class="col-md-6">
                    <x-select name="priority" label="Prioridad" :required="true" placeholder="Seleccione"
                              :options="['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta']"
                              :value="old('priority', 'media')"/>
                </div>
                <div class="col-md-12">
                    <x-text-input name="title" label="Título" :value="old('title')" :required="true"/>
                </div>
                    <div class="col-md-6">
                        <x-text-input name="scheduled_at" type="datetime-local" label="Fecha programada" :value="old('scheduled_at', now()->addDay()->format('Y-m-d\TH:i'))" :required="true"
                                      help="Se sincroniza con Google Calendar usando la zona horaria del responsable o del sistema."/>
                    </div>

                <div class="col-md-6">
                    <x-text-input name="reminder_at" type="datetime-local" label="Recordatorio" :value="old('reminder_at')"/>
                </div>
                <div class="col-md-12">
                    <x-label for="activity-description-{{ $subjectType }}-{{ $subject->getKey() }}" label="Descripción (opcional)"/>
                    <textarea name="description" id="activity-description-{{ $subjectType }}-{{ $subject->getKey() }}" rows="2"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                    <x-validation-error name="description"/>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" data-testid="btn-submit-activity">Registrar actividad</button>
            </div>
        </form>
    </x-modal>
@endif
