@extends('layouts.app')

@section('title', 'Prospectos')
@section('page-title', 'Prospectos')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Lead::class)
            <a href="{{ route('leads.create') }}" class="btn btn-primary" data-testid="btn-create-lead">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo prospecto
            </a>
        @endcan
        @can('leads.import')
            <a href="{{ route('leads.import') }}" class="btn btn-outline-secondary">
                <i class="bi bi-upload me-1" aria-hidden="true"></i> Importar
            </a>
        @endcan
        @can('leads.export')
            <a href="{{ route('leads.export', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar
            </a>
        @endcan
    </div>

    <x-table title="Listado de prospectos" data-testid="leads-table">
        @slot('filters')
            <form method="GET" action="{{ route('leads.index') }}" class="row g-2 align-items-end" data-testid="leads-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Código, nombre, documento..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="status_id" class="form-select form-select-sm" aria-label="Estado">
                        <option value="">Estado</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->id }}" @if ((string) ($filters['status_id'] ?? '') === (string) $status->id) selected @endif>
                                {{ $status->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="source_id" class="form-select form-select-sm" aria-label="Origen">
                        <option value="">Origen</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->id }}" @if ((string) ($filters['source_id'] ?? '') === (string) $source->id) selected @endif>
                                {{ $source->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @if (auth()->user()->can('leads.view.any') || auth()->user()->can('leads.view.team'))
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
                    <select name="interest_level" class="form-select form-select-sm" aria-label="Nivel de interés">
                        <option value="">Nivel de interés</option>
                        @foreach (['bajo' => 'Bajo', 'medio' => 'Medio', 'alto' => 'Alto'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['interest_level'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm" aria-label="Desde">
                </div>
                <div class="col-auto">
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm" aria-label="Hasta">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('leads.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Documento</th>
                <th>Estado</th>
                <th>Origen</th>
                <th>Responsable</th>
                <th>Próxima acción</th>
                <th>Ingresado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($leads as $lead)
                <tr data-testid="lead-row">
                    <td><a href="{{ route('leads.show', $lead) }}" class="fw-medium">{{ $lead->code }}</a></td>
                    <td>
                        {{ trim(($lead->first_name.' '.$lead->last_name)) }}
                        @if ($lead->company_name)
                            <div class="small text-secondary">{{ $lead->company_name }}</div>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $lead->doc_type ? strtoupper($lead->doc_type) : '' }} {{ $lead->doc_number }}</td>
                    <td><x-badge-status :status="$lead->status?->slug"/></td>
                    <td>{{ $lead->source?->name }}</td>
                    <td>{{ $lead->owner?->name }}</td>
                    <td class="text-nowrap">
                        @if (isset($nextActions[$lead->id]) && $nextActions[$lead->id] !== null)
                            <span class="d-block small fw-medium">{{ $nextActions[$lead->id]->title }}</span>
                            <span class="small text-secondary">{{ $nextActions[$lead->id]->scheduled_at->format('d/m/Y H:i') }}</span>
                        @else
                            <span class="text-secondary small">Sin próximo seguimiento</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $lead->entered_at?->format('d/m/Y') }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('leads.show', $lead) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $lead)
                            <a href="{{ route('leads.edit', $lead) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay prospectos registrados.',
                            'hint' => 'Ajuste los filtros o registre un nuevo prospecto.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $leads])
        @endslot
    </x-table>
@endsection
