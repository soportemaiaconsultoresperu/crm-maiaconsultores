@extends('layouts.app')

@section('title', 'Cliente '.$customer->code)
@section('page-title', $customer->code)

@section('content')
    @if ($customer->convertedFromLead !== null)
        <x-alert type="info" data-testid="convert-source-banner">
            <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>
            Cliente creado a partir del prospecto
            <a href="{{ route('leads.show', $customer->convertedFromLead) }}" class="fw-medium">{{ $customer->convertedFromLead->code }}</a>
            el {{ $customer->converted_at?->format('d/m/Y') }}. El historial comercial del prospecto se conserva en esta línea de tiempo (RF-CLI-006).
        </x-alert>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('update', $customer)
            <a href="{{ route('customers.edit', $customer) }}" class="btn btn-primary">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i> Editar
            </a>
        @endcan
        @can('delete', $customer)
            <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#customer-deactivate-modal">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
            </button>
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Datos del cliente</h3>
                    <x-badge-status :status="$customer->status === 'activo' ? 'active' : 'inactive'"/>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Código</dt>
                        <dd class="col-sm-7" data-testid="customer-code">{{ $customer->code }}</dd>

                        <dt class="col-sm-5">Tipo de persona</dt>
                        <dd class="col-sm-7">{{ $customer->person_type === 'natural' ? 'Persona natural' : 'Persona jurídica' }}</dd>

<dt class="col-sm-5">Razón social</dt>
                            <dd class="col-sm-7">{{ $customer->legal_name }}</dd>

                            @if ($customer->trade_name)
                                <dt class="col-sm-5">Nombre comercial</dt>
                                <dd class="col-sm-7">{{ $customer->trade_name }}</dd>
                            @endif

                            @if ($customer->first_name || $customer->last_name)
                                <dt class="col-sm-5">Contacto</dt>
                                <dd class="col-sm-7">
                                    {{ trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')) }}
                                    @if ($customer->position)
                                        <div class="small text-secondary">{{ $customer->position }}</div>
                                    @endif
                                </dd>
                            @endif

                        <dt class="col-sm-5">Documento</dt>
                        <dd class="col-sm-7">{{ $customer->doc_type ? strtoupper($customer->doc_type).' ' : '' }}{{ $customer->doc_number ?? '—' }}</dd>

                        <dt class="col-sm-5">Teléfono</dt>
                        <dd class="col-sm-7">{{ $customer->phone ?? '—' }}</dd>

                        <dt class="col-sm-5">WhatsApp</dt>
                        <dd class="col-sm-7">{{ $customer->whatsapp ?? '—' }}</dd>

                        <dt class="col-sm-5">Correo electrónico</dt>
                        <dd class="col-sm-7">{{ $customer->email ?? '—' }}</dd>

                        @if ($customer->website)
                            <dt class="col-sm-5">Sitio web</dt>
                            <dd class="col-sm-7"><a href="{{ $customer->website }}" target="_blank" rel="noopener">{{ $customer->website }}</a></dd>
                        @endif

<dt class="col-sm-5">Dirección fiscal</dt>
                            <dd class="col-sm-7">{{ $customer->fiscal_address ?? '—' }}</dd>

                            <dt class="col-sm-5">Dirección comercial</dt>
                            <dd class="col-sm-7">{{ $customer->address ?? '—' }}</dd>

                        <dt class="col-sm-5">Ubigeo</dt>
                        <dd class="col-sm-7">{{ $customer->ubigeo?->name ?? '—' }}</dd>

                        @if ($customer->sector)
                            <dt class="col-sm-5">Sector</dt>
                            <dd class="col-sm-7">{{ $customer->sector }}</dd>
                        @endif

                        <dt class="col-sm-5">Responsable</dt>
                        <dd class="col-sm-7">{{ $customer->owner?->name ?? '—' }}</dd>

                        @if ($customer->observations)
                            <dt class="col-sm-5">Observaciones</dt>
                            <dd class="col-sm-7">{{ $customer->observations }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card mt-3 text-secondary" data-testid="opportunities-placeholder">
                <div class="card-header"><h3 class="card-title mb-0">Oportunidades</h3></div>
                <div class="card-body">
                    <p class="mb-0 small">La gestión de oportunidades estará disponible en el bloque B04.</p>
                </div>
            </div>

<div class="card mt-3" data-testid="customer-quotations-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Cotizaciones</h3>
                        @can('create', App\Models\Quotation::class)
                            <a href="{{ route('customers.quotations.create', $customer) }}" class="btn btn-sm btn-primary" data-testid="btn-new-quotation">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva cotización
                            </a>
                        @endcan
                    </div>
                    <div class="card-body p-0 table-responsive">
                        @php
                            $customerQuotations = $customer->quotations()->with('owner')->orderByDesc('issued_at')->orderByDesc('id')->limit(10)->get();
                        @endphp
                        @if ($customerQuotations->isEmpty())
                            <div class="p-3">
                                @include('layouts.partials.empty-state', [
                                    'message' => 'Sin cotizaciones registradas.',
                                    'hint' => 'Cree la primera cotización para este cliente.',
                                ])
                            </div>
                        @else
                            <table class="table table-hover align-middle mb-0" data-testid="customer-quotations-table">
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
                                    @foreach ($customerQuotations as $quotation)
                                        <tr data-testid="customer-quotation-row">
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

                    @include('customers._payments_card', [
                        'customer' => $customer,
                        'canViewPayments' => $canViewPayments ?? false,
                        'canManagePayments' => $canManagePayments ?? false,
                        'invoiceStatuses' => $invoiceStatuses ?? collect(),
                    ])

                    @include('customers._products_card', ['customer' => $customer])
        </div>

        <div class="col-lg-7">
            <div class="card" data-testid="contacts-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Contactos</h3>
                    @can('contacts.create')
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#contact-create-modal">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar contacto
                        </button>
                    @endcan
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0" data-testid="contacts-table">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th>Cargo</th>
                                <th>Datos</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($customer->contacts as $contact)
                                <tr data-testid="contact-row">
                                    <td>
                                        {{ $contact->first_name }} {{ $contact->last_name }}
                                        @if ($contact->is_primary)
                                            <span class="badge text-bg-primary ms-1" data-testid="primary-badge">Principal</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $contact->position ?? '—' }}
                                        @if ($contact->area)
                                            <div class="small text-secondary">{{ $contact->area }}</div>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($contact->email)<div>{{ $contact->email }}</div>@endif
                                        @if ($contact->phone)<div>{{ $contact->phone }}</div>@endif
                                        @if (! $contact->email && ! $contact->phone)<span class="text-secondary">—</span>@endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if (! $contact->is_primary)
                                            @can('contacts.update')
                                                <form method="POST" action="{{ route('contacts.set-primary', $contact) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Establecer como principal">
                                                        <i class="bi bi-star me-1" aria-hidden="true"></i>
                                                    Principal</button>
                                                </form>
                                            @endcan
                                        @endif
                                        @can('update', $contact)
                                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar"
                                                    data-bs-toggle="modal" data-bs-target="#contact-edit-modal-{{ $contact->id }}">
                                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                                            Editar</button>
                                        @endcan
                                        @can('delete', $contact)
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="Desactivar"
                                                    data-bs-toggle="modal" data-bs-target="#contact-deactivate-modal-{{ $contact->id }}">
                                                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i>
                                            Desactivar</button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        @include('layouts.partials.empty-state', [
                                            'message' => 'Sin contactos registrados.',
                                            'hint' => 'Agregue el primer contacto de este cliente.',
                                        ])
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title mb-0">Historial comercial</h3></div>
                <div class="card-body">
                    @forelse ($history as $item)
                        <div class="d-flex gap-3 pb-3 mb-3 border-bottom" data-testid="history-item">
                            <i class="bi {{ $item['kind'] === 'activity' ? 'bi-chat-left-text' : 'bi-clock-history' }} fs-5 text-secondary" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1 fw-medium">
                                    {{ $item['title'] }}
                                    @if (($item['meta']['origin'] ?? null) === 'lead')
                                        <span class="badge text-bg-secondary ms-1" data-testid="origin-lead-badge">Lead</span>
                                    @endif
                                </p>
                                @if ($item['detail'])
                                    <p class="mb-1 small">{{ $item['detail'] }}</p>
                                @endif
                                <p class="mb-0 small text-secondary">
                                    {{ $item['at']->format('d/m/Y H:i') }}
                                    @if ($item['kind'] === 'activity' && isset($item['meta']['type']))
                                        — {{ $item['meta']['type'] }}
                                        — <x-badge-status :status="$item['meta']['status'] ?? ''"/>
                                    @elseif ($item['kind'] === 'log' && ! empty($item['meta']['event']))
                                        — {{ $item['meta']['event'] }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        @include('layouts.partials.empty-state', ['message' => 'Sin actividades ni cambios registrados.'])
                    @endforelse
                </div>
            </div>

@include('activities.partials._subject_section', [
                'activities' => $activities ?? collect(),
                'subjectType' => 'customer',
                'subject' => $customer,
                'nextAction' => $nextAction ?? null,
            ])

            @include('documents.partials._panel', [
                'subject' => $customer,
                'documents' => $customer->documents()->orderByDesc('uploaded_at')->orderByDesc('id')->get(),
            ])
        </div>
    </div>

    @can('contacts.create')
        <x-modal id="contact-create-modal" title="Agregar contacto">
            @include('customers._contact-form', ['customer' => $customer, 'contact' => null])
        </x-modal>
    @endcan

    @foreach ($customer->contacts as $contact)
        @can('update', $contact)
            <x-modal id="contact-edit-modal-{{ $contact->id }}" title="Editar contacto">
                @include('customers._contact-form', ['customer' => $customer, 'contact' => $contact])
            </x-modal>
        @endcan

        @can('delete', $contact)
            <x-swal-confirm
                :action="route('contacts.destroy', $contact)"
                method="DELETE"
                title="¿Desactivar contacto?"
                text="El contacto {{ $contact->first_name }} {{ $contact->last_name }} se desactivará; nunca se elimina físicamente (RF-CON-003). Si era el principal, el cliente quedará sin contacto principal hasta una reasignación explícita."
                type="warning"
                confirm-text="Sí, desactivar"
                input="textarea"
                input-name="reason"
                input-label="Motivo"
                input-required="true"
                input-placeholder="Indique el motivo de la desactivación…"
                button-class="btn-sm btn-outline-danger"
                title="Desactivar"
                class="d-inline">
                <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
            </x-swal-confirm>
        @endcan
    @endforeach

@can('delete', $customer)
        <x-swal-confirm
            :action="route('customers.destroy', $customer)"
            method="DELETE"
            title="¿Desactivar cliente?"
            text="El cliente {{ $customer->code }} se desactivará; nunca se elimina físicamente (RF-CLI-003)."
            type="warning"
            confirm-text="Sí, desactivar"
            input="textarea"
            input-name="reason"
            input-label="Motivo"
            input-required="true"
            input-placeholder="Indique el motivo de la desactivación…"
            button-class="btn-outline-danger ms-auto">
            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Desactivar
        </x-swal-confirm>
    @endcan
@endsection
