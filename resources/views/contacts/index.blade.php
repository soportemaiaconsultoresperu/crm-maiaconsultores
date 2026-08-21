@extends('layouts.app')

@section('title', 'Contactos')
@section('page-title', 'Contactos')

@section('content')
        <div class="d-flex flex-wrap gap-2 mb-3">
            @can('contacts.create')
                <a href="{{ route('contacts.create') }}" class="btn btn-primary" data-testid="btn-create-contact">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo contacto
                </a>
            @endcan
            <a href="{{ route('contacts.import') }}" class="btn btn-primary" data-testid="btn-import-contacts">
            <i class="bi bi-upload me-1" aria-hidden="true"></i> Importar
        </a>
        <a href="{{ route('contacts.import.template') }}" class="btn btn-outline-secondary" data-testid="btn-contacts-template">
            <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i> Descargar plantilla
        </a>
    </div>

    <x-table title="Listado de contactos" data-testid="contacts-table">
        @slot('filters')
            <form method="GET" action="{{ route('contacts.index') }}" class="row g-2 align-items-end" data-testid="contacts-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Nombre, correo o teléfono..." aria-label="Buscar">
                </div>
                @if (filled($filters['customer_id'] ?? null))
                    <input type="hidden" name="customer_id" value="{{ $filters['customer_id'] }}">
                @endif
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('contacts.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Contacto</th>
                <th>Cargo</th>
                <th>Área</th>
                <th>Email</th>
                <th>Teléfono</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($contacts as $contact)
                <tr data-testid="contact-row">
                    <td>
                        <span class="fw-medium">{{ $contact->first_name }} {{ $contact->last_name }}</span>
                        @if ($contact->is_primary)
                            <span class="badge text-bg-primary ms-1">Principal</span>
                        @endif
                    </td>
                    <td>{{ $contact->position }}</td>
                    <td>{{ $contact->area }}</td>
                    <td>{{ $contact->email }}</td>
                    <td class="text-nowrap">{{ $contact->phone }}</td>
                    <td>
                        <a href="{{ route('customers.show', $contact->customer) }}">{{ $contact->customer->legal_name }}</a>
                    </td>
                    <td>
                        @if ($contact->is_active)
                            <span class="badge text-bg-success">Activo</span>
                        @else
                            <span class="badge text-bg-secondary">Inactivo</span>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('customers.show', $contact->customer) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i> Ver
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay contactos registrados.',
                            'hint' => 'Agregue contactos desde la ficha de un cliente o impórtelos desde Excel.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $contacts])
        @endslot
    </x-table>
@endsection
