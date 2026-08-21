@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
    @php
        // Helpers kept inside the view to keep the controller thin and avoid
        // duplicating formatting logic across cards. ADR-004: never collapse
        // amounts across currencies; each currency keeps its own line.
        $money = static function (float $amount): string {
            return number_format($amount, 2, '.', ',');
        };
        $shortDate = static function (?string $iso): string {
            if (! $iso) {
                return '—';
            }
            return \Carbon\Carbon::parse($iso)->format('d/m/Y H:i');
        };
    @endphp

    {{-- ===================================================================
         ROW 1 — counters: prospectos / oportunidades / actividades (RF-DASH-001)
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-counters-row1">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Prospectos nuevos (semana)</p>
                    <p class="card-text h3 mb-0" data-testid="kpi-prospectos-nuevos">
                        {{ $prospectos_nuevos ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Prospectos sin contactar</p>
                    <p class="card-text h3 mb-0" data-testid="kpi-prospectos-sin-contactar">
                        {{ $prospectos_sin_contactar ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Oportunidades abiertas</p>
                    <p class="card-text h3 mb-0" data-testid="kpi-oportunidades-abiertas">
                        {{ $oportunidades_abiertas ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Actividades vencidas</p>
                    <p class="card-text h3 mb-0 text-danger" data-testid="kpi-actividades-vencidas">
                        {{ $actividades_vencidas ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================
         ROW 2 — counters + multimoneda funnel (RF-DASH-001 / ADR-004)
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-counters-row2">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Ventas ganadas (mes)</p>
                    <p class="card-text h3 mb-0 text-success" data-testid="kpi-ventas-ganadas">
                        {{ $ventas_ganadas_count ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Oportunidades perdidas (mes)</p>
                    <p class="card-text h3 mb-0 text-danger" data-testid="kpi-oportunidades-perdidas">
                        {{ $oportunidades_perdidas_count ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Actividades pendientes</p>
                    <p class="card-text h3 mb-0" data-testid="kpi-actividades-pendientes">
                        {{ $actividades_pendientes ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card h-100" data-testid="card-valor-embudo">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-2">Valor del embudo</p>
                    @php $buckets = $valor_embudo_by_currency ?? []; @endphp
                    @forelse ($buckets as $code => $amount)
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <span class="small text-secondary">{{ $code }}</span>
                            <span class="fw-medium">{{ $money((float) $amount) }}</span>
                        </div>
                    @empty
                        <p class="card-text text-secondary small mb-0">Sin oportunidades abiertas.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================
         ROW 3 — conversion por etapa (RF-DASH-002)
         Simple HTML table; the user requested NO chart deps.
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-conversiones">
        <div class="col-12">
            <x-table id="dashboard-conversiones" title="Conversiones por etapa">
                <x-slot:filters></x-slot:filters>
                <x-slot:headers>
                    <tr>
                        <th>Etapa</th>
                        <th>Tipo</th>
                        <th class="text-end">Oportunidades</th>
                    </tr>
                </x-slot:headers>
                <x-slot:rows>
                    @php $rows = $conversiones_por_etapa ?? []; @endphp
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>
                                <x-badge-status :status="$row['stage_type']"/>
                            </td>
                            <td class="text-end">{{ $row['count'] }}</td>
                        </tr>
                    @empty
                        @include('dashboard._empty_row', ['colspan' => 3, 'slot' => 'Sin etapas registradas.'])
                    @endforelse
                </x-slot:rows>
            </x-table>
        </div>
    </div>

    {{-- ===================================================================
         ROW 4 — próximas reuniones (RF-DASH-001)
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-proximas-reuniones">
        <div class="col-12">
            <x-table id="dashboard-reuniones" title="Próximas reuniones">
                <x-slot:filters></x-slot:filters>
                <x-slot:headers>
                    <tr>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Responsable</th>
                        <th>Programada</th>
                    </tr>
                </x-slot:headers>
                <x-slot:rows>
                    @php $reuniones = $proximas_reuniones ?? []; @endphp
                    @forelse ($reuniones as $reunion)
                        <tr>
                            <td>{{ $reunion['title'] }}</td>
                            <td>{{ $reunion['type'] ?? '—' }}</td>
                            <td>{{ $reunion['owner'] ?? '—' }}</td>
                            <td>{{ $shortDate($reunion['scheduled_at'] ?? null) }}</td>
                        </tr>
                    @empty
                        @include('dashboard._empty_row', ['colspan' => 4, 'slot' => 'No hay reuniones próximas.'])
                    @endforelse
                </x-slot:rows>
            </x-table>
        </div>
    </div>

    {{-- ===================================================================
         ROW 5 — rendimiento por vendedor (RF-DASH-003)
         One row per owner+currency; never collapse amounts.
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-rendimiento">
        <div class="col-12">
            <x-table id="dashboard-rendimiento" title="Rendimiento por vendedor (mes actual)">
                <x-slot:filters></x-slot:filters>
                <x-slot:headers>
                    <tr>
                        <th>Vendedor</th>
                        <th>Moneda</th>
                        <th class="text-end">Ganadas</th>
                        <th class="text-end">Monto ganado</th>
                    </tr>
                </x-slot:headers>
                <x-slot:rows>
                    @php $rows = $rendimiento_por_vendedor ?? []; @endphp
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row['owner'] }}</td>
                            <td>{{ $row['currency_code'] }}</td>
                            <td class="text-end">{{ $row['won_count'] }}</td>
                            <td class="text-end">{{ $money((float) $row['won_amount']) }}</td>
                        </tr>
                    @empty
                        @include('dashboard._empty_row', ['colspan' => 4, 'slot' => 'Aún no hay ventas ganadas este mes.'])
                    @endforelse
                </x-slot:rows>
            </x-table>
        </div>
    </div>
@endsection