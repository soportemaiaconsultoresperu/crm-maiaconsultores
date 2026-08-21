@extends('layouts.app')

@section('title', 'Cuentas de correo')
@section('page-title', 'Cuentas de correo')

@section('content')
    <p class="text-muted">
        Cuentas externas (SMTP, Gmail, Outlook) registradas para envío y
        recepción de correo. Cada cuenta respeta los permisos
        <code>email.account.manage</code> (CRUD) y
        <code>email.shared.use</code> (selección al enviar).
    </p>

    <x-table title="Cuentas de correo">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Etiqueta</th>
                <th>Proveedor</th>
                <th>Compartida</th>
                <th>Activa</th>
                <th>Modo prueba</th>
                <th>Última sincronización</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($accounts as $account)
                <tr>
                    <td>{{ $account->id }}</td>
                    <td>{{ $account->label }}</td>
                    <td><code>{{ $account->provider }}</code></td>
                    <td>
                        @if ($account->is_shared)
                            <span class="badge bg-info">Sí</span>
                        @else
                            <span class="badge bg-secondary">No</span>
                        @endif
                    </td>
                    <td>
                        @if ($account->is_active)
                            <span class="badge bg-success">Sí</span>
                        @else
                            <span class="badge bg-secondary">No</span>
                        @endif
                    </td>
                    <td>
                        @if ($account->test_mode)
                            <span class="badge bg-warning">Sí</span>
                        @else
                            <span class="badge bg-secondary">No</span>
                        @endif
                    </td>
                    <td>{{ optional($account->last_synced_at)->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        No hay cuentas de correo registradas.
                    </td>
                </tr>
            @endforelse

            @if (method_exists($accounts, 'links'))
                <tr>
                    <td colspan="7">{{ $accounts->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>
@endsection
