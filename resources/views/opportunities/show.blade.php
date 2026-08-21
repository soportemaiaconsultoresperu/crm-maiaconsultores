{{--
    Opportunity detail (RF-OPP-005/006/007/009): data card + won/lost banner
    + merged timeline + next action. Activities and quotations sections are
    placeholders until B05/B06. Win/lose/deactivate modals live here.
--}}
@extends('layouts.app')

@section('title', 'Oportunidad '.$opportunity->code)
@section('page-title', $opportunity->code)

@section('content')
    @php
        $stageType = $opportunity->stage?->stage_type;
        $symbol = $currencies[$opportunity->currency_code]->symbol ?? $opportunity->currency_code;
        $subject = $opportunity->customer
            ? $opportunity->customer->legal_name
            : trim(($opportunity->lead?->first_name.' '.($opportunity->lead?->last_name ?? '')) . ($opportunity->lead?->company_name ? ' — '.$opportunity->lead->company_name : ''));
        $isOpen = $stageType === 'open';
    @endphp

    @if ($stageType === 'won')
        <div class="alert alert-success d-flex align-items-center gap-2" role="alert" data-testid="won-banner">
            <i class="bi bi-trophy fs-5" aria-hidden="true"></i>
            <div>
                <strong>Oportunidad ganada.</strong>
                Monto final: {{ $symbol }} {{ number_format((float) $opportunity->final_amount, 2) }}
                — Cerrada el {{ $opportunity->closed_at?->format('d/m/Y') }}.
            </div>
        </div>
    @elseif ($stageType === 'lost')
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert" data-testid="lost-banner">
            <i class="bi bi-x-circle fs-5" aria-hidden="true"></i>
            <div>
                <strong>Oportunidad perdida.</strong>
                Motivo: {{ $opportunity->lossReason?->name ?? '—' }}
                — Cerrada el {{ $opportunity->closed_at?->format('d/m/Y') }}.
            </div>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('update', $opportunity)
            <a href="{{ route('opportunities.edit', $opportunity) }}" class="btn btn-primary" data-testid="btn-edit">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
            @if ($isOpen)
                @can('opportunities.win')
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#win-modal" data-testid="btn-win">
                        <i class="bi bi-trophy me-1" aria-hidden="true"></i> Marcar ganada
                    </button>
                @endcan
                @can('opportunities.lose')
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#lose-modal" data-testid="btn-lose">
                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i> Marcar perdida
                    </button>
                @endcan
            @endif
        @endcan
        @can('delete', $opportunity)
            <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#deactivate-modal">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
            </button>
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Datos de la oportunidad</h3>
                    <x-badge-status :status="$stageType ?? 'open'"/>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Código</dt>
                        <dd class="col-sm-7">{{ $opportunity->code }}</dd>

                        <dt class="col-sm-5">Título</dt>
                        <dd class="col-sm-7">{{ $opportunity->title }}</dd>

                        <dt class="col-sm-5">Etapa</dt>
                        <dd class="col-sm-7">{{ $opportunity->stage?->name }}</dd>

                        <dt class="col-sm-5">{{ $opportunity->customer ? 'Cliente' : 'Lead' }}</dt>
                        <dd class="col-sm-7">
                            @if ($opportunity->customer)
                                <a href="{{ route('customers.show', $opportunity->customer) }}">{{ $opportunity->customer->legal_name }}</a>
                            @elseif ($opportunity->lead)
                                <a href="{{ route('leads.show', $opportunity->lead) }}">{{ $subject }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        @if ($opportunity->contact)
                            <dt class="col-sm-5">Contacto</dt>
                            <dd class="col-sm-7">{{ trim($opportunity->contact->first_name.' '.$opportunity->contact->last_name) }}</dd>
                        @endif

                        <dt class="col-sm-5">Monto estimado</dt>
                        <dd class="col-sm-7">{{ $symbol }} {{ number_format((float) $opportunity->estimated_amount, 2) }} <span class="text-secondary">({{ $opportunity->currency_code }})</span></dd>

                        <dt class="col-sm-5">Probabilidad</dt>
                        <dd class="col-sm-7">{{ $opportunity->probability !== null ? (float) $opportunity->probability.' %' : '—' }}</dd>

                        <dt class="col-sm-5">Prioridad</dt>
                        <dd class="col-sm-7">{{ $opportunity->priority ? ucfirst($opportunity->priority) : '—' }}</dd>

                        <dt class="col-sm-5">Responsable</dt>
                        <dd class="col-sm-7">{{ $opportunity->owner?->name ?? '—' }}</dd>

                        <dt class="col-sm-5">Origen</dt>
                        <dd class="col-sm-7">{{ $opportunity->source?->name ?? '—' }}</dd>

                        <dt class="col-sm-5">Cierre estimado</dt>
                        <dd class="col-sm-7">{{ $opportunity->expected_close_at?->format('d/m/Y') ?? '—' }}</dd>

                        @if ($stageType === 'won')
                            <dt class="col-sm-5">Monto final</dt>
                            <dd class="col-sm-7">{{ $symbol }} {{ number_format((float) $opportunity->final_amount, 2) }}</dd>

                            <dt class="col-sm-5">Fecha de cierre</dt>
                            <dd class="col-sm-7">{{ $opportunity->closed_at?->format('d/m/Y') }}</dd>
                        @endif

                        @if ($stageType === 'lost')
                            <dt class="col-sm-5">Motivo de pérdida</dt>
                            <dd class="col-sm-7">{{ $opportunity->lossReason?->name ?? '—' }}</dd>

                            <dt class="col-sm-5">Fecha de cierre</dt>
                            <dd class="col-sm-7">{{ $opportunity->closed_at?->format('d/m/Y') }}</dd>
                        @endif

                        @if ($opportunity->description)
                            <dt class="col-sm-5">Descripción</dt>
                            <dd class="col-sm-7">{{ $opportunity->description }}</dd>
                        @endif

                        <dt class="col-sm-5">Próxima acción</dt>
                        <dd class="col-sm-7">
                            @if ($nextAction)
                                <span class="d-block fw-medium">{{ $nextAction->title }}</span>
                                <span class="text-secondary">{{ $nextAction->scheduled_at->format('d/m/Y H:i') }}</span>
                            @else
                                <span class="text-secondary">Sin próximo seguimiento</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Historial</h3>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush" data-testid="opportunity-timeline">
                        @forelse ($history as $item)
                            @php
                                $icons = ['stage' => 'bi-arrow-left-right', 'activity' => 'bi-calendar-event', 'log' => 'bi-clock-history'];
                            @endphp
                            <li class="list-group-item d-flex gap-3">
                                <i class="bi {{ $icons[$item['kind']] ?? 'bi-dot' }} text-secondary mt-1" aria-hidden="true"></i>
                                <div>
                                    <div class="fw-medium">
                                        {{ $item['title'] }}
                                        @if ($item['kind'] === 'stage' && ($item['meta']['user'] ?? null))
                                            <span class="text-secondary fw-normal">— {{ $item['meta']['user'] }}</span>
                                        @endif
                                    </div>
                                    @if ($item['detail'])
                                        <div class="small">{{ $item['detail'] }}</div>
                                    @endif
                                    <div class="small text-secondary">{{ $item['at']->format('d/m/Y H:i') }}</div>
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item">
                                @include('layouts.partials.empty-state', ['message' => 'Sin movimientos registrados.'])
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>

<div class="card mt-3" data-testid="opportunity-quotations-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Cotizaciones</h3>
                        @can('create', App\Models\Quotation::class)
                            <a href="{{ route('opportunities.quotations.create', $opportunity) }}" class="btn btn-sm btn-primary" data-testid="btn-new-quotation">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva cotización
                            </a>
                        @endcan
                    </div>
                    <div class="card-body p-0 table-responsive">
                        @php
                            $oppQuotations = $opportunity->quotations()->with('owner')->orderByDesc('issued_at')->orderByDesc('id')->limit(10)->get();
                        @endphp
                        @if ($oppQuotations->isEmpty())
                            <div class="p-3">
                                @include('layouts.partials.empty-state', [
                                    'message' => 'Sin cotizaciones asociadas.',
                                    'hint' => 'Cree una cotización vinculada a esta oportunidad.',
                                ])
                            </div>
                        @else
                            <table class="table table-hover align-middle mb-0" data-testid="opportunity-quotations-table">
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
                                    @foreach ($oppQuotations as $quotation)
                                        <tr data-testid="opportunity-quotation-row">
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

@include('activities.partials._subject_section', [
                'activities' => $activities ?? collect(),
                'subjectType' => 'opportunity',
                'subject' => $opportunity,
                'nextAction' => $nextAction ?? null,
            ])

            @include('documents.partials._panel', [
                'subject' => $opportunity,
                'documents' => $opportunity->documents()->orderByDesc('uploaded_at')->orderByDesc('id')->get(),
            ])
        </div>
    </div>

    {{-- Modal: marcar ganada (RF-OPP-006) --}}
    <x-modal id="win-modal" title="Marcar como ganada">
        <form method="POST" action="{{ route('opportunities.win', $opportunity) }}" data-testid="win-form">
            @csrf
            <div class="mb-3">
                <x-text-input name="final_amount" type="number" label="Monto final" :value="old('final_amount', $opportunity->estimated_amount)" :required="true" step="0.01" min="0.01"/>
                <div class="form-text">Moneda de la oportunidad: {{ $opportunity->currency_code }}.</div>
            </div>
            <div class="mb-3">
                <x-text-input name="closed_at" type="date" label="Fecha de cierre (opcional)" :value="old('closed_at', now()->toDateString())"/>
            </div>
            <button type="submit" class="btn btn-success">Confirmar ganada</button>
        </form>
    </x-modal>

    {{-- Modal: marcar perdida (RF-OPP-007) --}}
    <x-modal id="lose-modal" title="Marcar como perdida">
        <form method="POST" action="{{ route('opportunities.lose', $opportunity) }}" data-testid="lose-form">
            @csrf
            <div class="mb-3">
                <x-select name="loss_reason_id" label="Motivo de pérdida" :required="true"
                          :options="$lossReasons->mapWithKeys(fn ($r) => [$r->id => $r->name])->all()"
                          :value="old('loss_reason_id')" :placeholder="'Seleccione un motivo'"/>
            </div>
            <div class="mb-3">
                <label for="lose_note" class="form-label mb-1">Nota (opcional)</label>
                <textarea name="note" id="lose_note" rows="3" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                <x-validation-error name="note"/>
            </div>
            <button type="submit" class="btn btn-danger">Confirmar pérdida</button>
        </form>
    </x-modal>

    {{-- Modal: desactivar --}}
    <x-modal id="deactivate-modal" title="Desactivar oportunidad">
        <form method="POST" action="{{ route('opportunities.destroy', $opportunity) }}" data-testid="deactivate-form">
            @csrf
            <div class="mb-3">
                <label for="deactivate_reason" class="form-label mb-1">Motivo <span class="text-danger">*</span></label>
                <textarea name="reason" id="deactivate_reason" rows="3" class="form-control @error('reason') is-invalid @enderror"
                          required>{{ old('reason') }}</textarea>
                <x-validation-error name="reason"/>
            </div>
            <button type="submit" class="btn btn-outline-danger">Desactivar</button>
        </form>
    </x-modal>
@endsection
