@extends('layouts.app')

@section('title', 'Calendario — Lista')
@section('page-title', 'Calendario (lista)')

@section('content')
    <div data-calendar-page>
        @include('calendar.partials._nav', ['view' => $view, 'anchor' => $anchor, 'prevAnchor' => $prevAnchor, 'nextAnchor' => $nextAnchor, 'filters' => $filters, 'owners' => $owners, 'types' => $types])

        <x-table title="Eventos del rango" data-testid="calendar-list">
        @slot('headers')
            <tr>
                <th>Fecha y hora</th>
                <th>Título</th>
                <th>Tipo</th>
                <th>Sujeto</th>
                <th>Estado</th>
                <th>Responsable</th>
                <th class="text-end">Acción</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($events as $event)
                    <tr data-testid="calendar-list-row">
                    <td class="text-nowrap">{{ $event->scheduled_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $event->title }}</td>
                    <td>{{ $event->typeLabel }}</td>
                    <td>{{ $event->subjectLabel }}</td>
                    <td><x-badge-status :status="$event->status"/></td>
                    <td>{{ $event->ownerName ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ $event->url }}" class="btn btn-sm btn-outline-secondary">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @include('layouts.partials.empty-state', ['message' => 'No hay eventos en el rango seleccionado.'])
                    </td>
                </tr>
            @endforelse
        @endslot
        </x-table>
    </div>
@endsection
