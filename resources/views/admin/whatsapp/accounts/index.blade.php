@extends('layouts.app')

@section('title', 'Cuentas de WhatsApp')
@section('page-title', 'Cuentas de WhatsApp')

@section('content')
    <p class="text-muted">
        Bandeja de entrada B14 — cuentas de WhatsApp conectadas al CRM.
        Las plantillas se sincronizan desde Meta; las conversaciones se
        asignan por equipo o individualmente respetando
        <code>DataScopeService</code> (decisiones 14a–c).
    </p>

    <x-table title="Cuentas de WhatsApp">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Phone ID</th>
                <th>Estado</th>
                <th>Verificado</th>
                <th>Último evento</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($accounts as $account)
                <tr>
                    <td>{{ $account->id }}</td>
                    <td>{{ $account->display_name ?: '—' }}</td>
                    <td><code>{{ $account->phone_number }}</code></td>
                    <td><code>{{ $account->phone_number_id ?: '—' }}</code></td>
                    <td>
                        <span class="badge bg-{{ $account->status === 'verified' ? 'success' : 'secondary' }}">
                            {{ $account->status ?: '—' }}
                        </span>
                    </td>
                    <td>{{ optional($account->verified_at)->format('Y-m-d H:i') ?: '—' }}</td>
                    <td>{{ optional($account->last_event_at)->format('Y-m-d H:i') ?: '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('whatsapp.accounts.show', $account) }}"
                           class="btn btn-sm btn-outline-primary">
                            Ver
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        No hay cuentas de WhatsApp registradas todavía.
                    </td>
                </tr>
            @endforelse

            @if (method_exists($accounts, 'links'))
                <tr>
                    <td colspan="8">{{ $accounts->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>
@endsection