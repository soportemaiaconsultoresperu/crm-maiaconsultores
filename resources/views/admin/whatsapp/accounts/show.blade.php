@extends('layouts.app')

@section('title', 'Cuenta de WhatsApp — '.$account->display_name)
@section('page-title', 'Cuenta '.$account->display_name)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('whatsapp.accounts.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Cuentas
        </a>
        @can('whatsapp.template.manage')
            <form method="POST" action="{{ route('whatsapp.accounts.sync', $account) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('¿Sincronizar plantillas de Meta para esta cuenta?')">
                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                    Sincronizar plantillas
                </button>
            </form>
        @endcan
        <a href="{{ route('whatsapp.templates.index', ['account_id' => $account->id]) }}"
           class="btn btn-outline-info">
            <i class="bi bi-card-list me-1" aria-hidden="true"></i> Ver plantillas
        </a>
        <a href="{{ route('whatsapp.conversations.index') }}"
           class="btn btn-outline-secondary">
            <i class="bi bi-inbox me-1" aria-hidden="true"></i> Bandeja
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Datos de la cuenta</h3></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">ID interno</dt>
                <dd class="col-sm-9">{{ $account->id }}</dd>

                <dt class="col-sm-3">Nombre visible</dt>
                <dd class="col-sm-9">{{ $account->display_name ?: '—' }}</dd>

                <dt class="col-sm-3">Teléfono</dt>
                <dd class="col-sm-9"><code>{{ $account->phone_number }}</code></dd>

                <dt class="col-sm-3">Phone number ID</dt>
                <dd class="col-sm-9"><code>{{ $account->phone_number_id ?: '—' }}</code></dd>

                <dt class="col-sm-3">Business ID</dt>
                <dd class="col-sm-9"><code>{{ $account->business_id ?: '—' }}</code></dd>

                <dt class="col-sm-3">Estado</dt>
                <dd class="col-sm-9">
                    <span class="badge bg-{{ $account->status === 'verified' ? 'success' : 'secondary' }}">
                        {{ $account->status ?: '—' }}
                    </span>
                </dd>

                <dt class="col-sm-3">Verificado</dt>
                <dd class="col-sm-9">{{ optional($account->verified_at)->format('Y-m-d H:i') ?: '—' }}</dd>

                <dt class="col-sm-3">Último evento</dt>
                <dd class="col-sm-9">{{ optional($account->last_event_at)->format('Y-m-d H:i') ?: '—' }}</dd>
            </dl>
        </div>
    </div>

    <x-table title="Conversaciones recientes (últimas 20)">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Teléfono</th>
                <th>Contacto</th>
                <th>Estado</th>
                <th>Asignado a</th>
                <th>Último mensaje</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($recentConversations as $conversation)
                <tr>
                    <td>{{ $conversation->id }}</td>
                    <td><code>{{ $conversation->phone_number }}</code></td>
                    <td>{{ $conversation->contact_name ?: '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $conversation->status === 'open' ? 'success' : 'secondary' }}">
                            {{ $conversation->status }}
                        </span>
                    </td>
                    <td>{{ $conversation->assignee?->name ?? '—' }}</td>
                    <td>{{ optional($conversation->last_message_at)->format('Y-m-d H:i') ?: '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('whatsapp.conversations.show', $conversation) }}"
                           class="btn btn-sm btn-outline-primary">
                            Abrir
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        Esta cuenta aún no tiene conversaciones registradas.
                    </td>
                </tr>
            @endforelse
        @endslot
    </x-table>
@endsection