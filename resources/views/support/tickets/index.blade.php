@extends('layouts.app')

@section('title', 'Soporte')
@section('page-title', 'Soporte técnico')

@section('content')
    <div class="d-flex justify-content-end mb-3">
        @can('create', \App\Models\SupportTicket::class)
            <a href="{{ route('support.tickets.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo ticket
            </a>
        @endcan
    </div>

    @php($isFiltered = filled($filters['q'] ?? null) || filled($filters['status_id'] ?? null) || filled($filters['priority_id'] ?? null) || ! empty($filters['mine']))

    <x-table title="Tickets de soporte">
        <x-slot:filters>
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="q">Buscar</label>
                    <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Código, cliente o título">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="status_id">Estado</label>
                    <select id="status_id" name="status_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->id }}" @selected(($filters['status_id'] ?? '') == $status->id)>{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="priority_id">Prioridad</label>
                    <select id="priority_id" name="priority_id" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->id }}" @selected(($filters['priority_id'] ?? '') == $priority->id)>{{ $priority->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="mine" value="1" id="mine" @checked(! empty($filters['mine']))>
                        <label class="form-check-label" for="mine">Mis tickets</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Filtrar</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('support.tickets.index') }}">Limpiar</a>
                </div>
            </form>
        </x-slot:filters>

        <x-slot:headers>
            <tr>
                <th>Código</th>
                <th>Título</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th>Prioridad</th>
                <th>Responsable</th>
                <th>Actualizado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </x-slot:headers>

        <x-slot:rows>
            @forelse ($tickets as $ticket)
                <tr>
                    <td>{{ $ticket->code }}</td>
                    <td>{{ $ticket->title }}</td>
                    <td>{{ $ticket->customer?->trade_name ?: $ticket->customer?->legal_name ?: $ticket->customer?->code }}</td>
                    <td>{{ $ticket->status?->name }}</td>
                    <td>{{ $ticket->priority?->name }}</td>
                    <td>{{ $ticket->responsible?->name ?? 'Sin asignar' }}</td>
                    <td class="text-nowrap" title="{{ $ticket->updated_at?->format('d/m/Y H:i') }}">{{ $ticket->updated_at?->format('d/m/Y H:i') }}</td>
                    <td class="text-end">
                        <a href="{{ route('support.tickets.show', $ticket) }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                @include('layouts.partials.empty-state', ['colspan' => 8, 'message' => $isFiltered ? 'No hay tickets que coincidan con la búsqueda.' : 'No hay tickets de soporte.'])
            @endforelse
        </x-slot:rows>

        <x-slot:pagination>
            @include('layouts.partials.pagination', ['paginator' => $tickets])
        </x-slot:pagination>
    </x-table>
@endsection
