@extends('layouts.app')

@section('title', 'Cotizaciones')
@section('page-title', 'Cotizaciones')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\Quotation::class)
            <a href="{{ route('quotations.create') }}" class="btn btn-primary" data-testid="btn-create-quotation">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva cotización
            </a>
        @endcan
        @can('quotations.export')
            <a href="{{ route('quotations.export', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar
            </a>
        @endcan
    </div>

    <x-table title="Listado de cotizaciones" data-testid="quotations-table">
        @slot('filters')
            <form method="GET" action="{{ route('quotations.index') }}" class="row g-2 align-items-end" data-testid="quotations-filters">
                <div class="col-auto">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Número, términos..." aria-label="Buscar">
                </div>
                <div class="col-auto">
                    <select name="status" class="form-select form-select-sm" aria-label="Estado">
                        <option value="">Estado</option>
                        @foreach ([
                            'draft' => 'Borrador',
                            'sent' => 'Enviada',
                            'accepted' => 'Aceptada',
                            'rejected' => 'Rechazada',
                            'expired' => 'Vencida',
                            'voided' => 'Anulada',
                        ] as $value => $label)
                            <option value="{{ $value }}" @if (($filters['status'] ?? '') === $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="customer_id" class="form-select form-select-sm" aria-label="Cliente">
                        <option value="">Cliente</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @if ((string) ($filters['customer_id'] ?? '') === (string) $customer->id) selected @endif>
                                {{ $customer->legal_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="lead_id" class="form-select form-select-sm" aria-label="Lead">
                        <option value="">Lead</option>
                        @foreach ($leads as $lead)
                            <option value="{{ $lead->id }}" @if ((string) ($filters['lead_id'] ?? '') === (string) $lead->id) selected @endif>
                                {{ $lead->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <select name="opportunity_id" class="form-select form-select-sm" aria-label="Oportunidad">
                        <option value="">Oportunidad</option>
                        @foreach ($opportunities as $opportunity)
                            <option value="{{ $opportunity->id }}" @if ((string) ($filters['opportunity_id'] ?? '') === (string) $opportunity->id) selected @endif>
                                {{ $opportunity->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @if (auth()->user()->can('quotations.view.any') || auth()->user()->can('quotations.view.team'))
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
                    <select name="currency_code" class="form-select form-select-sm" aria-label="Moneda">
                        <option value="">Moneda</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}" @if (($filters['currency_code'] ?? '') === $currency->code) selected @endif>
                                {{ $currency->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <input type="date" name="issued_at_from" value="{{ $filters['issued_at_from'] ?? '' }}"
                           class="form-control form-control-sm" aria-label="Emisión desde">
                </div>
                <div class="col-auto">
                    <input type="date" name="issued_at_to" value="{{ $filters['issued_at_to'] ?? '' }}"
                           class="form-control form-control-sm" aria-label="Emisión hasta">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                    <a href="{{ route('quotations.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endslot

        @slot('headers')
            <tr>
                <th>Número</th>
                <th>Estado</th>
                <th>Cliente / Lead</th>
                <th>Oportunidad</th>
                <th>Responsable</th>
                <th>Emisión</th>
                <th>Moneda</th>
                <th class="text-end">Total</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($quotations as $quotation)
                <tr data-testid="quotation-row">
                    <td>
                        <a href="{{ route('quotations.show', $quotation) }}" class="fw-medium">{{ $quotation->number }}</a>
                    </td>
                    <td><x-badge-status :status="$quotation->status"/></td>
                    <td>
                        @if ($quotation->customer)
                            <a href="{{ route('customers.show', $quotation->customer) }}">{{ $quotation->customer->legal_name }}</a>
                            <div class="small text-secondary">{{ $quotation->customer->code }}</div>
                        @elseif ($quotation->lead)
                            <a href="{{ route('leads.show', $quotation->lead) }}">{{ $quotation->lead->code }}</a>
                            <div class="small text-secondary">{{ trim($quotation->lead->first_name.' '.($quotation->lead->last_name ?? '')) }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($quotation->opportunity)
                            <a href="{{ route('opportunities.show', $quotation->opportunity) }}">{{ $quotation->opportunity->code }}</a>
                        @else
                            <span class="text-secondary small">—</span>
                        @endif
                    </td>
                    <td>{{ $quotation->owner?->name }}</td>
                    <td class="text-nowrap">{{ $quotation->issued_at?->format('d/m/Y') }}</td>
                    <td>{{ $quotation->currency_code }}</td>
                    <td class="text-end">{{ number_format((float) $quotation->total, 2) }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('quotations.show', $quotation) }}" class="btn btn-sm btn-outline-secondary" title="Ver">
                            <i class="bi bi-eye me-1" aria-hidden="true"></i>
                        Ver</a>
                        <a href="{{ route('quotations.pdf', $quotation) }}" class="btn btn-sm btn-outline-secondary" title="PDF">
                            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>
                        PDF</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay cotizaciones registradas.',
                            'hint' => 'Cree una nueva cotización desde un lead, cliente u oportunidad.',
                        ])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $quotations])
        @endslot
    </x-table>
@endsection