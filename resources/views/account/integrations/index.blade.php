@extends('layouts.app')

@section('title', 'Integraciones')
@section('page-title', 'Integraciones')

@section('content')
    @php
        $account = $google['account'];
        $services = $google['services'];
        $statusLabels = [
            'not_connected' => 'No conectado',
            'connected' => 'Conectado',
            'sync_pending' => 'Sincronización pendiente',
            'syncing' => 'Sincronizando',
            'temporary_error' => 'Error temporal',
            'reconnection_required' => 'Requiere reconexión',
            'disconnected' => 'Desconectado',
        ];
        $status = $google['status'];
    @endphp

    <div class="row g-3" data-testid="google-integrations-page">
        <div class="col-12">
            <div class="alert alert-info mb-0">
                Gmail y Google Calendar usan una única identidad Google conectada a tu usuario del CRM.
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100" data-testid="gmail-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0">Gmail</h3>
                    <span class="badge text-bg-{{ $services['gmail'] ? 'success' : 'secondary' }}">
                        {{ $services['gmail'] ? 'Conectado' : 'No conectado' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Cuenta Google:</strong> {{ $google['email'] ?? '—' }}</p>
                    <p class="mb-3"><strong>Estado:</strong> {{ $statusLabels[$status] ?? $status }}</p>
                    <p class="text-secondary small mb-0">Permite enviar cotizaciones por Gmail desde la cuenta Google conectada.</p>
                </div>
                <div class="card-footer d-flex gap-2">
                    @if ($services['gmail'])
                        <form method="POST" action="{{ route('account.integrations.google.disable', 'gmail') }}">
                            @csrf
                            @method('PATCH')
                            <button class="btn btn-outline-secondary">Desactivar Gmail</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('account.integrations.google.connect', 'gmail') }}">
                            @csrf
                            <button class="btn btn-primary">Conectar Gmail</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100" data-testid="calendar-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0">Google Calendar</h3>
                    <span class="badge text-bg-{{ $services['calendar'] ? 'success' : 'secondary' }}">
                        {{ $services['calendar'] ? 'Conectado' : 'No conectado' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Cuenta Google:</strong> {{ $google['email'] ?? '—' }}</p>
                    <p class="mb-1"><strong>Estado:</strong> {{ $statusLabels[$status] ?? $status }}</p>
                    @if ($services['calendar'] && $calendarWatch !== null)
                        <p class="mb-3" data-testid="calendar-watch-status">
                            <strong>Watch Google:</strong> {{ $calendarWatch->status }}
                            @if ($calendarWatch->expires_at !== null)
                                · vence {{ $calendarWatch->expires_at->diffForHumans() }}
                            @endif
                        </p>
                    @else
                        <p class="mb-3"><strong>Watch Google:</strong> —</p>
                    @endif
                    <p class="text-secondary small mb-0">Sincroniza actividades futuras del CRM hacia Google Calendar. No importa eventos desde Google.</p>

                    @if ($services['calendar'] && $calendarInitialSyncCount > 0)
                        <div class="alert alert-warning mt-3 mb-0" data-testid="calendar-initial-sync-confirmation">
                            Se crearán {{ $calendarInitialSyncCount }} eventos futuros en Google Calendar. ¿Querés continuar?
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    @if ($services['calendar'])
                        @if ($calendarInitialSyncCount > 0)
                            <form method="POST" action="{{ route('account.integrations.google.calendar.initial-sync') }}">
                                @csrf
                                <button class="btn btn-primary" data-testid="calendar-initial-sync-submit">
                                    Sincronizar {{ $calendarInitialSyncCount }} actividades
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('account.integrations.google.disable', 'calendar') }}">
                            @csrf
                            @method('PATCH')
                            <button class="btn btn-outline-secondary">Desactivar Calendar</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('account.integrations.google.connect', 'calendar') }}">
                            @csrf
                            <button class="btn btn-primary">Conectar Google Calendar</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        @if ($account !== null && $account->is_active)
            <div class="col-12">
                <div class="card border-danger" data-testid="google-disconnect-card">
                    <div class="card-body d-flex flex-wrap justify-content-between gap-2 align-items-center">
                        <div>
                            <h3 class="h6 mb-1">Desconectar Google</h3>
                            <p class="text-secondary mb-0">Esto inutiliza los tokens locales y puede revocar la autorización completa en Google.</p>
                        </div>
                        <form method="POST" action="{{ route('account.integrations.google.disconnect') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger">Desconectar Google</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
