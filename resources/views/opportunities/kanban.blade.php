{{--
    Pipeline Kanban (RF-OPP-003/004). One column per active OPEN stage;
    ganada/perdida are NOT board columns (they belong to the win/lose flows).

    Expected data: $stages (open, ordered), $opportunitiesByStage (grouped
    by stage_id), $nextActions (batched map, ADR-012), $currencies (keyBy
    code), $owners, $filters, $total.

    Drag & drop is vanilla JS (no jQuery, ADR-010): optimistic DOM move +
    fetch POST opportunities/{id}/stage; on failure the card reverts and an
    alert is shown. Without JS, each card carries a "mover a" select that
    submits the same endpoint via a regular form (RF-OPP-004).
--}}
@extends('layouts.app')

@section('title', 'Pipeline de oportunidades')
@section('page-title', 'Oportunidades')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Opportunity::class)
            <a href="{{ route('opportunities.create') }}" class="btn btn-primary" data-testid="btn-create-opportunity">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva oportunidad
            </a>
        @endcan
        <a href="{{ route('opportunities.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-list-ul me-1" aria-hidden="true"></i> Listado
        </a>
        @can('opportunities.export')
            <a href="{{ route('opportunities.export', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar
            </a>
        @endcan
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('opportunities.kanban') }}" class="row g-2 align-items-end" data-testid="kanban-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Código, título, cliente o lead..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="priority" class="form-select form-select-sm" aria-label="Prioridad">
                        <option value="">Prioridad</option>
                        @foreach (['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['priority'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (auth()->user()->can('opportunities.view.any') || auth()->user()->can('opportunities.view.team'))
                    <div class="col-auto">
                        <select name="owner_id" class="form-select form-select-sm" aria-label="Responsable">
                            <option value="">Responsable</option>
                            @foreach ($owners as $owner)
                                <option value="{{ $owner->id }}" @if ((string) ($filters['owner_id'] ?? '') === (string) $owner->id) selected @endif>
                                    {{ $owner->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('opportunities.kanban') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

        <div class="row g-3" data-testid="kanban-board" data-kanban-board>
            @foreach ($stages as $stage)
                @php
                    $stageOpportunities = $opportunitiesByStage->get($stage->id, collect());
                    $sums = $stageOpportunities->groupBy('currency_code')
                        ->map(fn ($group) => $group->sum(fn ($o) => (float) $o->estimated_amount));
                @endphp
                <div class="col-12 col-md-6 col-xl-4 col-xxl">
                    <div class="card h-100 kanban-column" data-stage-column="{{ $stage->id }}">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0 fs-6">{{ $stage->name }}</h3>
                            <span class="badge text-bg-secondary kanban-count"
                                  data-testid="kanban-count-{{ $stage->slug }}">{{ $stageOpportunities->count() }}</span>
                        </div>
                        <div class="card-body kanban-cards" data-stage-cards="{{ $stage->id }}" data-stage-name="{{ $stage->name }}" style="min-height: 4rem;">
                            @forelse ($stageOpportunities as $opportunity)
                                @php
                                    $symbol = $currencies[$opportunity->currency_code]->symbol ?? $opportunity->currency_code;
                                    $subject = $opportunity->customer?->legal_name
                                        ?? trim(($opportunity->lead?->first_name.' '.($opportunity->lead?->last_name ?? '')) . ($opportunity->lead?->company_name ? ' — '.$opportunity->lead->company_name : ''));
                                    $nextAction = $nextActions[$opportunity->id] ?? null;
                                @endphp
                                <div class="card mb-2 kanban-card" draggable="true"
                                     data-opportunity-id="{{ $opportunity->id }}"
                                     data-stage-url="{{ route('opportunities.stage', $opportunity) }}"
                                     data-currency="{{ $opportunity->currency_code }}"
                                     data-amount="{{ (float) $opportunity->estimated_amount }}"
                                     data-testid="kanban-card-{{ $opportunity->code }}">
                                    <div class="card-body p-2">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <a href="{{ route('opportunities.show', $opportunity) }}" class="fw-medium small">{{ $opportunity->code }}</a>
                                            <span class="badge {{ ['alta' => 'text-bg-danger', 'media' => 'text-bg-warning', 'baja' => 'text-bg-secondary'][$opportunity->priority] ?? 'text-bg-secondary' }}">
                                                {{ ucfirst($opportunity->priority) }}
                                            </span>
                                        </div>
                                        <div class="small">{{ $opportunity->title }}</div>
                                        <div class="fw-medium small">{{ $symbol }} {{ number_format((float) $opportunity->estimated_amount, 2) }}</div>
                                        <div class="small text-secondary">{{ $subject }}</div>
                                        <div class="small text-secondary">{{ $opportunity->owner?->name }}</div>
                                        @if ($nextAction)
                                            <div class="small mt-1">
                                                <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                                                <span title="{{ $nextAction->title }}">{{ $nextAction->scheduled_at->format('d/m/Y H:i') }}</span>
                                            </div>
                                        @endif

                                        {{-- Fallback sin JS (RF-OPP-004): mismo endpoint POST stage. --}}
                                        <form method="POST" action="{{ route('opportunities.stage', $opportunity) }}"
                                              class="mt-2" data-testid="move-form-{{ $opportunity->code }}">
                                            @csrf
                                            <div class="input-group input-group-sm">
                                                <select name="stage_id" class="form-select form-select-sm" aria-label="Mover a">
                                                    @foreach ($stages as $optionStage)
                                                        @if ($optionStage->id !== $opportunity->stage_id)
                                                            <option value="{{ $optionStage->id }}">{{ $optionStage->name }}</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-outline-secondary">Mover</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <p class="text-secondary small text-center py-3 mb-0 kanban-empty">Sin oportunidades</p>
                            @endforelse
                        </div>
                        <div class="card-footer small text-secondary kanban-totals" data-testid="kanban-totals-{{ $stage->slug }}">
                            @foreach ($sums as $code => $sum)
                                <span class="me-2">{{ $currencies[$code]->symbol ?? $code }} {{ number_format((float) $sum, 2) }}</span>
                            @endforeach
                            @if ($sums->isEmpty())<span>—</span>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
@endsection
@push('scripts')
    <script>
        (function () {
            var board = document.querySelector('[data-kanban-board]');
            if (!board) {
                return;
            }

            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            var dragged = null;

            function refreshTotals() {
                board.querySelectorAll('.kanban-column').forEach(function (column) {
                    var cards = column.querySelectorAll('.kanban-card');
                    var count = column.querySelector('.kanban-count');
                    var totals = column.querySelector('.kanban-totals');

                    if (count) {
                        count.textContent = cards.length;
                    }

                    if (totals) {
                        var sums = {};
                        cards.forEach(function (card) {
                            var code = card.dataset.currency;
                            sums[code] = (sums[code] || 0) + parseFloat(card.dataset.amount || 0);
                        });

                        var parts = Object.keys(sums).map(function (code) {
                            return code + ' ' + sums[code].toLocaleString('es-PE', { minimumFractionDigits: 2 });
                        });

                        totals.textContent = parts.length ? parts.join(' · ') : '—';
                    }
                });
            }

            board.addEventListener('dragstart', function (event) {
                var card = event.target.closest ? event.target.closest('.kanban-card') : null;
                if (!card) {
                    return;
                }

                dragged = card;
                card.classList.add('opacity-50');
                event.dataTransfer.setData('text/plain', card.dataset.opportunityId);
                event.dataTransfer.effectAllowed = 'move';
            });

            board.addEventListener('dragend', function () {
                if (dragged) {
                    dragged.classList.remove('opacity-50');
                }
                dragged = null;
            });

            board.querySelectorAll('.kanban-cards').forEach(function (column) {
                column.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    column.classList.add('bg-body-secondary');
                });

                column.addEventListener('dragleave', function () {
                    column.classList.remove('bg-body-secondary');
                });

                column.addEventListener('drop', function (event) {
                    event.preventDefault();
                    column.classList.remove('bg-body-secondary');

                    if (!dragged) {
                        return;
                    }

                    var card = dragged;
                    var source = card.closest('.kanban-cards');
                    var nextSibling = card.nextSibling;
                    dragged = null;

                    if (source === column) {
                        return;
                    }

                    column.insertBefore(card, column.querySelector('.kanban-empty') || null);
                    fetch(card.dataset.stageUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ stage_id: column.dataset.stageCards }),
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('stage move rejected');
                        }
                        return response.json();
                    }).then(function () {
                        // Rebuild the no-JS "mover a" select so the new stage
                        // is excluded and the previous one offered again.
                        var select = card.querySelector('select[name="stage_id"]');
                        if (select) {
                            select.querySelectorAll('option').forEach(function (option) {
                                option.disabled = option.value === column.dataset.stageCards;
                                option.hidden = option.disabled;
                            });
                        }
                        refreshTotals();
                    }).catch(function () {
                        source.insertBefore(card, nextSibling);
                        refreshTotals();
                        alert('No se pudo mover la oportunidad. Inténtelo nuevamente o use el selector «Mover a».');
                    });
                });
            });
        })();
    </script>
@endpush
