@extends('layouts.app')

@section('title', 'Clientes')
@section('page-title', 'Clientes')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Customer::class)
            <a href="{{ route('customers.create') }}" class="btn btn-primary" data-testid="btn-create-customer">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo cliente
            </a>
        @endcan
        @can('customers.export')
            <a href="{{ route('customers.export', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar
            </a>
        @endcan
    </div>

    <x-table title="Listado de clientes" data-testid="customers-table">
        @slot('filters')
            <form method="GET" action="{{ route('customers.index') }}" class="row g-2 align-items-end" data-testid="customers-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Código, razón social, documento..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="person_type" class="form-select form-select-sm" aria-label="Tipo de persona">
                        <option value="">Tipo de persona</option>
                        @foreach (['natural' => 'Persona natural', 'juridica' => 'Persona jurídica'] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['person_type'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (auth()->user()->can('customers.view.any') || auth()->user()->can('customers.view.team'))
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
                    <a href="{{ route('customers.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Código</th>
                <th>Razón social</th>
                <th>Documento</th>
                <th>Contactos</th>
                <th>Responsable</th>
                <th>Próxima acción</th>
                <th>Registro</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($customers as $customer)
                <tr data-testid="customer-row">
                    <td><a href="{{ route('customers.show', $customer) }}" class="fw-medium">{{ $customer->code }}</a></td>
                    <td>
                        {{ $customer->legal_name }}
                        @if ($customer->trade_name)
                            <div class="small text-secondary">{{ $customer->trade_name }}</div>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $customer->doc_type ? strtoupper($customer->doc_type) : '' }} {{ $customer->doc_number }}</td>
                    <td class="text-center">{{ $customer->contacts_count }}</td>
                    <td>{{ $customer->owner?->name }}</td>
                    <td class="text-nowrap">
                        @if (isset($nextActions[$customer->id]) && $nextActions[$customer->id] !== null)
                            <span class="d-block small fw-medium">{{ $nextActions[$customer->id]->title }}</span>
                            <span class="small text-secondary">{{ $nextActions[$customer->id]->scheduled_at->format('d/m/Y H:i') }}</span>
                        @else
                            <span class="text-secondary small">Sin próximo seguimiento</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $customer->created_at?->format('d/m/Y') }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        @can('update', $customer)
                            <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                            Editar</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay clientes registrados.',
                            'hint' => 'Convierta un prospecto o registre un nuevo cliente.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $customers])
        @endslot
    </x-table>
@endsection
