@extends('layouts.app')

@section('title', 'Prospecto '.$lead->code)
@section('page-title', $lead->code)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('update', $lead)
            <a href="{{ route('leads.edit', $lead) }}" class="btn btn-primary">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
        @endcan
        @can('leads.assign')
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#assign-modal">
                <i class="bi bi-person-arrows me-1" aria-hidden="true"></i> Reasignar
            </button>
        @endcan
        @can('leads.convert')
            @if ($lead->convertedCustomers->isEmpty() && ! $lead->status?->is_final)
                <a href="{{ route('leads.convert', $lead) }}" class="btn btn-success" data-testid="btn-convert">
                    <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i> Convertir a cliente
                </a>
            @endif
        @endcan
        @can('delete', $lead)
            <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#deactivate-modal">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
            </button>
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Datos del prospecto</h3>
                    <x-badge-status :status="$lead->status?->slug"/>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Código</dt>
                        <dd class="col-sm-7">{{ $lead->code }}</dd>

                        <dt class="col-sm-5">Tipo de persona</dt>
                        <dd class="col-sm-7">{{ $lead->person_type === 'natural' ? 'Persona natural' : 'Persona jurídica' }}</dd>

                        <dt class="col-sm-5">Nombres</dt>
                        <dd class="col-sm-7">{{ $lead->first_name }}</dd>

                        @if ($lead->last_name)
                            <dt class="col-sm-5">Apellidos</dt>
                            <dd class="col-sm-7">{{ $lead->last_name }}</dd>
                        @endif

@if ($lead->company_name)
                            <dt class="col-sm-5">Empresa</dt>
                            <dd class="col-sm-7">{{ $lead->company_name }}</dd>
                        @endif

                        @if ($lead->legal_name)
                            <dt class="col-sm-5">Razón social</dt>
                            <dd class="col-sm-7">{{ $lead->legal_name }}</dd>
                        @endif

                        @if ($lead->trade_name)
                            <dt class="col-sm-5">Nombre comercial</dt>
                            <dd class="col-sm-7">{{ $lead->trade_name }}</dd>
                        @endif

                        @if ($lead->person_type === 'juridica' && $lead->primaryContact)
                            <dt class="col-sm-5">Contacto principal</dt>
                            <dd class="col-sm-7">{{ $lead->primaryContact->first_name }} {{ $lead->primaryContact->last_name }}</dd>

                            @if ($lead->primaryContact->position)
                                <dt class="col-sm-5">Cargo del contacto</dt>
                                <dd class="col-sm-7">{{ $lead->primaryContact->position }}</dd>
                            @endif

                            <dt class="col-sm-5">Contacto</dt>
                            <dd class="col-sm-7">
                                {{ collect([$lead->primaryContact->phone, $lead->primaryContact->whatsapp, $lead->primaryContact->email])->filter()->join(' · ') }}
                            </dd>
                        @endif

                        @if ($lead->person_type === 'natural')
                            @if ($lead->position)
                                <dt class="col-sm-5">Cargo</dt>
                                <dd class="col-sm-7">{{ $lead->position }}</dd>
                            @endif

                            <dt class="col-sm-5">Teléfono</dt>
                            <dd class="col-sm-7">{{ $lead->phone ?? '—' }}</dd>

                            <dt class="col-sm-5">WhatsApp</dt>
                            <dd class="col-sm-7">{{ $lead->whatsapp ?? '—' }}</dd>

                            <dt class="col-sm-5">Correo electrónico</dt>
                            <dd class="col-sm-7">{{ $lead->email ?? '—' }}</dd>
                        @endif

                        <dt class="col-sm-5">Documento</dt>
                        <dd class="col-sm-7">{{ $lead->doc_type ? strtoupper($lead->doc_type).' ' : '' }}{{ $lead->doc_number ?? '—' }}</dd>

                            @if ($lead->website)
                                <dt class="col-sm-5">Sitio web</dt>
                                <dd class="col-sm-7"><a href="{{ $lead->website }}" target="_blank" rel="noopener">{{ $lead->website }}</a></dd>
                            @endif

                            <dt class="col-sm-5">Dirección</dt>
                            <dd class="col-sm-7">{{ $lead->address ?? '—' }}</dd>

                            <dt class="col-sm-5">Ubigeo</dt>
                            <dd class="col-sm-7">{{ $lead->ubigeo?->name ?? '—' }}</dd>

                            @if ($lead->sector)
                                <dt class="col-sm-5">Sector</dt>
                                <dd class="col-sm-7">{{ $lead->sector }}</dd>
                            @endif

                        <dt class="col-sm-5">Origen</dt>
                        <dd class="col-sm-7">{{ $lead->source?->name ?? '—' }}</dd>

                        <dt class="col-sm-5">Nivel de interés</dt>
                        <dd class="col-sm-7">{{ $lead->interest_level ? ucfirst($lead->interest_level) : '—' }}</dd>

                        <dt class="col-sm-5">Responsable</dt>
                        <dd class="col-sm-7">{{ $lead->owner?->name ?? '—' }}</dd>

                        <dt class="col-sm-5">Fecha de ingreso</dt>
                        <dd class="col-sm-7">{{ $lead->entered_at?->format('d/m/Y') ?? '—' }}</dd>

                        @if ($lead->observations)
                            <dt class="col-sm-5">Observaciones</dt>
                            <dd class="col-sm-7">{{ $lead->observations }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title mb-0">Próxima acción</h3></div>
                <div class="card-body" data-testid="next-action">
                    @if ($nextAction !== null)
                        <p class="mb-1 fw-medium">{{ $nextAction->title }}</p>
                        <p class="mb-1 text-secondary">
                            <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                            {{ $nextAction->scheduled_at->format('d/m/Y H:i') }}
                        </p>
                        @if ($nextAction->type)
                            <p class="mb-0 small text-secondary">Tipo: {{ $nextAction->type->name }}</p>
                        @endif
                    @else
                        <p class="text-secondary mb-0">Sin próximo seguimiento</p>
                    @endif
                </div>
            </div>

<div class="card mt-3" data-testid="lead-quotations-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Cotizaciones</h3>
                        @can('create', App\Models\Quotation::class)
                            <a href="{{ route('leads.quotations.create', $lead) }}" class="btn btn-sm btn-primary" data-testid="btn-new-quotation">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva cotización
                            </a>
                        @endcan
                    </div>
                    <div class="card-body p-0 table-responsive">
                        @php
                            $leadQuotations = $lead->quotations()->with('owner')->orderByDesc('issued_at')->orderByDesc('id')->limit(10)->get();
                        @endphp
                        @if ($leadQuotations->isEmpty())
                            <div class="p-3">
                                @include('layouts.partials.empty-state', [
                                    'message' => 'Sin cotizaciones registradas.',
                                    'hint' => 'Cree la primera cotización para este prospecto.',
                                ])
                            </div>
                        @else
                            <table class="table table-hover align-middle mb-0" data-testid="lead-quotations-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Número</th>
                                        <th>Estado</th>
                                        <th>Emisión</th>
                                        <th>Responsable</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($leadQuotations as $quotation)
                                        <tr data-testid="lead-quotation-row">
                                            <td>{{ $quotation->number }}</td>
                                            <td><x-badge-status :status="$quotation->status"/></td>
                                            <td class="text-nowrap">{{ $quotation->issued_at?->format('d/m/Y') }}</td>
                                            <td>{{ $quotation->owner?->name }}</td>
                                            <td class="text-end">{{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }}</td>
                                            <td class="text-end text-nowrap">
                                                <a href="{{ route('quotations.show', $quotation) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                                                    <i class="bi bi-eye me-1" aria-hidden="true"></i>
                                                Ver</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Historial</h3></div>
                <div class="card-body">
                    @forelse ($history as $item)
                        <div class="d-flex gap-3 pb-3 mb-3 border-bottom" data-testid="history-item">
                            <i class="bi {{ $item['kind'] === 'activity' ? 'bi-chat-left-text' : 'bi-clock-history' }} fs-5 text-secondary" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1 fw-medium">{{ $item['title'] }}</p>
                                @if ($item['detail'])
                                    <p class="mb-1 small">{{ $item['detail'] }}</p>
                                @endif
                                <p class="mb-0 small text-secondary">
                                    {{ $item['at']->format('d/m/Y H:i') }}
                                    @if ($item['kind'] === 'activity' && isset($item['meta']['type']))
                                        — {{ $item['meta']['type'] }}
                                        — <x-badge-status :status="$item['meta']['status'] ?? ''"/>
                                    @elseif ($item['kind'] === 'log' && ! empty($item['meta']['event']))
                                        — {{ $item['meta']['event'] }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        @include('layouts.partials.empty-state', ['message' => 'Sin actividades ni cambios registrados.'])
                    @endforelse
                </div>
            </div>

@include('activities.partials._subject_section', [
                'activities' => $activities ?? collect(),
                'subjectType' => 'lead',
                'subject' => $lead,
                'nextAction' => $nextAction,
            ])

            @include('documents.partials._panel', [
                'subject' => $lead,
                'documents' => $lead->documents()->orderByDesc('uploaded_at')->orderByDesc('id')->get(),
            ])
        </div>
    </div>

    @can('leads.assign')
        <x-modal id="assign-modal" title="Reasignar prospecto">
            <form method="POST" action="{{ route('leads.assign', $lead) }}">
                @csrf
                <p class="text-secondary">Responsable actual: {{ $lead->owner?->name ?? '—' }}</p>
                <x-select name="owner_id" label="Nuevo responsable" :required="true"
                         :options="\App\Models\User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()"/>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Reasignar</button>
                </div>
            </form>
        </x-modal>
    @endcan

@can('delete', $lead)
        <x-swal-confirm
            :action="route('leads.destroy', $lead)"
            method="DELETE"
            title="¿Desactivar prospecto?"
            text="El prospecto {{ $lead->code }} se desactivará; nunca se elimina físicamente (RF-LEAD-011)."
            type="warning"
            confirm-text="Sí, desactivar"
            input="textarea"
            input-name="reason"
            input-label="Motivo"
            input-required="true"
            input-placeholder="Indique el motivo de la desactivación…"
            button-class="btn-outline-danger">
            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
        </x-swal-confirm>
    @endcan
@endsection
