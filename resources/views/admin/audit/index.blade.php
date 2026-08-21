@extends('layouts.app')

@section('title', 'Auditoría')
@section('page-title', 'Auditoría')

@section('content')
    <x-table title="Registro de auditoría">
        @slot('filters')
            <form method="GET" action="{{ route('admin.audit.index') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-1">Sujeto (clase)</label>
                    <select name="subject_type" class="form-select form-select-sm">
                        <option value="">Cualquier sujeto</option>
                        @foreach ($subjectTypes as $subjectType)
                            <option value="{{ $subjectType }}" @selected(($filters['subject_type'] ?? '') === $subjectType)>{{ $subjectType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">ID sujeto</label>
                    <input type="number" name="subject_id" value="{{ $filters['subject_id'] ?? '' }}" class="form-control form-control-sm" min="1">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Usuario</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Cualquier usuario</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Evento</label>
                    <select name="event" class="form-select form-select-sm">
                        <option value="">Cualquier evento</option>
                        @foreach ($events as $event)
                            <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ $event }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Desde</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Hasta</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Fecha</th>
                <th>Sujeto</th>
                <th>ID</th>
                <th>Usuario</th>
                <th>Evento</th>
                <th>Descripción</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($entries as $entry)
                <tr>
                    <td class="text-nowrap small">{{ $entry->created_at->format('d/m/Y H:i:s') }}</td>
                    <td class="small">{{ class_basename($entry->subject_type ?? '') }}</td>
                    <td>{{ $entry->subject_id }}</td>
                    <td>{{ $entry->causer?->name ?? '—' }}</td>
                    <td><span class="badge text-bg-secondary">{{ $entry->event }}</span></td>
                    <td class="small">{{ $entry->description }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.audit.show', $entry) }}" class="btn btn-sm btn-outline-secondary" title="Ver detalle">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @include('layouts.partials.empty-state', ['message' => 'No hay entradas que coincidan con el filtro.'])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $entries])
        @endslot
    </x-table>
@endsection