{{--
    Opportunities list view (RF-OPP-008/010): table alternative to the Kanban
    board. Expected data: $opportunities (paginated), $nextActions, $stages
    (all active), $currencies (keyBy code), $owners, $filters.
--}}
@extends('layouts.app')

@section('title', 'Oportunidades')
@section('page-title', 'Oportunidades')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Opportunity::class)
            <a href="{{ route('opportunities.create') }}" class="btn btn-primary" data-testid="btn-create-opportunity">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva oportunidad
            </a>
        @endcan
        <a href="{{ route('opportunities.kanban') }}" class="btn btn-outline-secondary" data-testid="btn-kanban">
            <i class="bi bi-kanban me-1" aria-hidden="true"></i> Pipeline Kanban
        </a>
        @can('opportunities.export')
            <a href="{{ route('opportunities.export', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar
            </a>
        @endcan
    </div>

    <x-table title="Listado de oportunidades" data-testid="opportunities-table">
        @slot('filters')
            <form method="GET" action="{{ route('opportunities.index') }}" class="row g-2 align-items-end" data-testid="opportunities-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Código, título, cliente o lead..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="stage_id" class="form-select form-select-sm" aria-label="Etapa">
                        <option value="">Etapa</option>
                        @foreach ($stages as $stage)
                            <option value="{{ $stage->id }}" @if ((string) ($filters['stage_id'] ?? '') === (string) $stage->id) selected @endif>
                                {{ $stage->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="status" class="form-select form-select-sm" aria-label="Estado">
                        <option value="">Estado</option>
                        @foreach (['open' => 'Abierta', 'won' => 'Ganada', 'lost' => 'Perdida'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['status'] ?? '') === $value) selected @endif>{{ $label }}</option>
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
                <div class="col-auto">
                    <select name="priority" class="form-select form-select-sm" aria-label="Prioridad">
                        <option value="">Prioridad</option>
                        @foreach (['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['priority'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('opportunities.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Código</th>
                <th>Título</th>
                <th>Cliente / Lead</th>
                <th>Etapa</th>
                <th>Monto estimado</th>
                <th>Prioridad</th>
                <th>Responsable</th>
                <th>Próxima acción</th>
                <th>Cierre estimado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($opportunities as $opportunity)
                @php
                    $symbol = $currencies[$opportunity->currency_code]->symbol ?? $opportunity->currency_code;
                    $subject = $opportunity->customer?->legal_name
                        ?? trim(($opportunity->lead?->first_name.' '.($opportunity->lead?->last_name ?? '')) . ($opportunity->lead?->company_name ? ' — '.$opportunity->lead->company_name : ''));
                @endphp
                <tr data-testid="opportunity-row">
                    <td><a href="{{ route('opportunities.show', $opportunity) }}" class="fw-medium">{{ $opportunity->code }}</a></td>
                    <td>{{ $opportunity->title }}</td>
                    <td>{{ $subject }}</td>
                    <td>
                        <x-badge-status :status="$opportunity->stage?->stage_type"/>
                        <div class="small text-secondary">{{ $opportunity->stage?->name }}</div>
                    </td>
                    <td class="text-nowrap">{{ $symbol }} {{ number_format((float) $opportunity->estimated_amount, 2) }}</td>
                    <td>{{ $opportunity->priority ? ucfirst($opportunity->priority) : '—' }}</td>
                    <td>{{ $opportunity->owner?->name }}</td>
                    <td class="text-nowrap">
                        @if (isset($nextActions[$opportunity->id]) && $nextActions[$opportunity->id] !== null)
                            <span class="d-block small fw-medium">{{ $nextActions[$opportunity->id]->title }}</span>
                            <span class="small text-secondary">{{ $nextActions[$opportunity->id]->scheduled_at->format('d/m/Y H:i') }}</span>
                        @else
                            <span class="text-secondary small">Sin próximo seguimiento</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $opportunity->expected_close_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('opportunities.show', $opportunity) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $opportunity)
                            <a href="{{ route('opportunities.edit', $opportunity) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay oportunidades registradas.',
                            'hint' => 'Ajuste los filtros o registre una nueva oportunidad.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $opportunities])
        @endslot
    </x-table>
@endsection
