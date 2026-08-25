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
        $stageRows = $conversiones_por_etapa ?? [];
        $maxStageCount = max(1, ...array_map(static fn (array $row): int => (int) ($row['count'] ?? 0), $stageRows ?: [['count' => 0]]));
        $salesRows = $rendimiento_por_vendedor ?? [];
        $maxSalesCount = max(1, ...array_map(static fn (array $row): int => (int) ($row['won_count'] ?? 0), $salesRows ?: [['won_count' => 0]]));
        $wonCount = (int) ($ventas_ganadas_count ?? 0);
        $lostCount = (int) ($oportunidades_perdidas_count ?? 0);
        $closedTotal = max(1, $wonCount + $lostCount);
        $wonPercent = max(4, (int) round(($wonCount / $closedTotal) * 100));
        $lostPercent = max(4, 100 - $wonPercent);
        $pendingActivities = (int) ($actividades_pendientes ?? 0);
        $overdueActivities = (int) ($actividades_vencidas ?? 0);
        $activityTotal = max(1, $pendingActivities + $overdueActivities);
        $pendingPercent = max(4, (int) round(($pendingActivities / $activityTotal) * 100));
        $overduePercent = max(4, 100 - $pendingPercent);
        $dailyRows = $actividad_por_dia ?? [];
        $sourceRows = $prospectos_por_origen ?? [];
        $dailyMax = max(1, ...array_map(static fn (array $row): int => (int) ($row['total_count'] ?? 0), $dailyRows ?: [['total_count' => 0]]));
        $dailyYAxisMax = max(2, $dailyMax);
        $dailyYAxisMid = (int) ceil($dailyYAxisMax / 2);
        $dailyPoints = [];
        $dailyAreaPoints = [];
        $dailyCount = max(1, count($dailyRows) - 1);
        foreach ($dailyRows as $dailyIndex => $row) {
            $x = 10 + (($dailyIndex / $dailyCount) * 280);
            $y = 105 - (((int) ($row['total_count'] ?? 0) / $dailyYAxisMax) * 85);
            $dailyPoints[] = round($x, 2).','.round($y, 2);
            $dailyAreaPoints[] = round($x, 2).','.round($y, 2);
        }
        $dailyArea = $dailyAreaPoints ? '10,112 '.implode(' ', $dailyAreaPoints).' 290,112' : '';
        $maxSourceCount = max(1, ...array_map(static fn (array $row): int => (int) ($row['count'] ?? 0), $sourceRows ?: [['count' => 0]]));
        $sourceTotal = array_sum(array_map(static fn (array $row): int => (int) ($row['count'] ?? 0), $sourceRows));
    @endphp

    {{-- ===================================================================
         ROW 1 — counters: prospectos / oportunidades / actividades (RF-DASH-001)
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-counters-row1">
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Prospectos nuevos (semana)</p><p class="card-text h3 mb-0" data-testid="kpi-prospectos-nuevos">{{ $prospectos_nuevos ?? 0 }}</p></div><span class="dashboard-kpi-icon text-bg-primary" aria-hidden="true"><i class="bi bi-person-plus"></i></span></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Prospectos sin contactar</p><p class="card-text h3 mb-0" data-testid="kpi-prospectos-sin-contactar">{{ $prospectos_sin_contactar ?? 0 }}</p></div><span class="dashboard-kpi-icon dashboard-kpi-icon-purple" aria-hidden="true"><i class="bi bi-person-exclamation"></i></span></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Oportunidades abiertas</p><p class="card-text h3 mb-0" data-testid="kpi-oportunidades-abiertas">{{ $oportunidades_abiertas ?? 0 }}</p></div><span class="dashboard-kpi-icon dashboard-kpi-icon-blue" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Actividades vencidas</p><p class="card-text h3 mb-0 text-danger" data-testid="kpi-actividades-vencidas">{{ $actividades_vencidas ?? 0 }}</p></div><span class="dashboard-kpi-icon dashboard-kpi-icon-orange" aria-hidden="true"><i class="bi bi-alarm"></i></span></div></div></div></div>
    </div>

    {{-- ===================================================================
         ROW 2 — counters + multimoneda funnel (RF-DASH-001 / ADR-004)
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-counters-row2">
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Ventas ganadas (mes)</p><p class="card-text h3 mb-0 text-success" data-testid="kpi-ventas-ganadas">{{ $ventas_ganadas_count ?? 0 }}</p></div><span class="dashboard-kpi-icon dashboard-kpi-icon-green" aria-hidden="true"><i class="bi bi-trophy"></i></span></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Oportunidades perdidas (mes)</p><p class="card-text h3 mb-0 text-danger" data-testid="kpi-oportunidades-perdidas">{{ $oportunidades_perdidas_count ?? 0 }}</p></div><span class="dashboard-kpi-icon dashboard-kpi-icon-red" aria-hidden="true"><i class="bi bi-x-octagon"></i></span></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card h-100 dashboard-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><p class="card-text small text-uppercase text-secondary mb-1">Actividades pendientes</p><p class="card-text h3 mb-0" data-testid="kpi-actividades-pendientes">{{ $actividades_pendientes ?? 0 }}</p></div><span class="dashboard-kpi-icon dashboard-kpi-icon-purple" aria-hidden="true"><i class="bi bi-check2-square"></i></span></div></div></div></div>
        <div class="col-12 col-md-3">
            <div class="card h-100 dashboard-kpi-card" data-testid="card-valor-embudo">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-2"><p class="card-text small text-uppercase text-secondary mb-0">Valor del embudo</p><span class="dashboard-kpi-icon dashboard-kpi-icon-green" aria-hidden="true"><i class="bi bi-cash-stack"></i></span></div>
                    @php $buckets = $valor_embudo_by_currency ?? []; @endphp
                    @forelse ($buckets as $code => $amount)
                        <div class="d-flex justify-content-between border-bottom py-1"><span class="small text-secondary">{{ $code }}</span><span class="fw-medium">{{ $money((float) $amount) }}</span></div>
                    @empty
                        <p class="card-text text-secondary small mb-0">Sin oportunidades abiertas.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================
         ROW 3 — visual analytics without external chart dependencies.
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-analytics-charts">
        <div class="col-12 col-xl-7">
            <div class="card h-100 dashboard-chart-card" data-testid="dashboard-conversiones">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <p class="card-text small text-uppercase text-secondary mb-1">Embudo comercial</p>
                            <h3 class="h5 mb-0">Conversión por etapa</h3>
                        </div>
                        <span class="dashboard-kpi-icon dashboard-kpi-icon-blue" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span>
                    </div>
                    @forelse ($stageRows as $row)
                        @php
                            $stageCount = (int) ($row['count'] ?? 0);
                            $stagePercent = max(4, (int) round(($stageCount / $maxStageCount) * 100));
                        @endphp
                            <div class="dashboard-bar-row">
                                <div class="d-flex justify-content-between gap-3 mb-1">
                                    <span class="fw-semibold">{{ $row['name'] }}</span>
                                    <span class="text-secondary small">{{ $stageCount }} oportunidades</span>
                                </div>
                                <div class="dashboard-bar-track" role="img" aria-label="{{ $row['name'] }}: {{ $stageCount }} oportunidades">
                                    <svg class="dashboard-bar-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                                        <rect class="dashboard-bar-fill" x="0" y="0" width="{{ $stagePercent }}" height="10" rx="5"></rect>
                                    </svg>
                                </div>
                            </div>
                    @empty
                        <p class="text-secondary mb-0">Sin etapas registradas.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card h-100 dashboard-chart-card">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Salud del mes</p>
                    <h3 class="h5 mb-3">Ganadas vs perdidas</h3>
                        <div class="dashboard-stacked-bar mb-3" role="img" aria-label="{{ $wonCount }} ganadas y {{ $lostCount }} perdidas este mes">
                            <svg class="dashboard-bar-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                                <rect class="dashboard-stacked-bar-success" x="0" y="0" width="{{ $wonPercent }}" height="10"></rect>
                                <rect class="dashboard-stacked-bar-danger" x="{{ $wonPercent }}" y="0" width="{{ $lostPercent }}" height="10"></rect>
                            </svg>
                        </div>
                    <div class="row g-2 mb-4">
                        <div class="col-6"><div class="dashboard-mini-stat text-success"><strong>{{ $wonCount }}</strong><span>Ganadas</span></div></div>
                        <div class="col-6"><div class="dashboard-mini-stat text-danger"><strong>{{ $lostCount }}</strong><span>Perdidas</span></div></div>
                    </div>
                    <p class="card-text small text-uppercase text-secondary mb-1">Actividades</p>
                    <h3 class="h5 mb-3">Pendientes vs vencidas</h3>
                        <div class="dashboard-stacked-bar mb-3" role="img" aria-label="{{ $pendingActivities }} pendientes y {{ $overdueActivities }} vencidas">
                            <svg class="dashboard-bar-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                                <rect class="dashboard-stacked-bar-primary" x="0" y="0" width="{{ $pendingPercent }}" height="10"></rect>
                                <rect class="dashboard-stacked-bar-warning" x="{{ $pendingPercent }}" y="0" width="{{ $overduePercent }}" height="10"></rect>
                            </svg>
                        </div>
                    <div class="row g-2">
                        <div class="col-6"><div class="dashboard-mini-stat text-primary"><strong>{{ $pendingActivities }}</strong><span>Pendientes</span></div></div>
                        <div class="col-6"><div class="dashboard-mini-stat text-warning"><strong>{{ $overdueActivities }}</strong><span>Vencidas</span></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================
         ROW 4 — richer visual analytics: line, vertical bars and timeline.
         =================================================================== --}}
    <div class="row g-3 mb-3" data-testid="dashboard-expanded-analytics">
        <div class="col-12 col-xl-5">
            <div class="card h-100 dashboard-chart-card" data-testid="dashboard-tendencia-comercial">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <p class="card-text small text-uppercase text-secondary mb-1">Últimos 7 días</p>
                            <h3 class="h5 mb-0">Tendencia comercial</h3>
                        </div>
                        <span class="dashboard-kpi-icon dashboard-kpi-icon-purple" aria-hidden="true"><i class="bi bi-activity"></i></span>
                    </div>

                    <div class="dashboard-line-chart-frame">
                        <div class="dashboard-line-y-axis" aria-hidden="true">
                            <span>{{ $dailyYAxisMax }}</span>
                            <span>{{ $dailyYAxisMid }}</span>
                            <span>0</span>
                        </div>
                        <div class="dashboard-line-chart" role="img" aria-label="Tendencia comercial de los últimos siete días. Eje vertical: cantidad total; eje horizontal: fecha.">
                            <span class="dashboard-line-axis-title">Cantidad</span>
                            <svg viewBox="0 0 300 130" preserveAspectRatio="none" aria-hidden="true">
                                <line class="dashboard-line-axis" x1="10" y1="112" x2="290" y2="112"></line>
                                <line class="dashboard-line-axis" x1="10" y1="70" x2="290" y2="70"></line>
                                <line class="dashboard-line-axis" x1="10" y1="28" x2="290" y2="28"></line>
                                @if ($dailyArea)
                                    <polygon class="dashboard-line-area" points="{{ $dailyArea }}"></polygon>
                                    <polyline class="dashboard-line-series" points="{{ implode(' ', $dailyPoints) }}"></polyline>
                                    @foreach ($dailyPoints as $point)
                                        @php [$cx, $cy] = explode(',', $point); @endphp
                                        <circle class="dashboard-line-dot" cx="{{ $cx }}" cy="{{ $cy }}" r="3.8"></circle>
                                    @endforeach
                                @endif
                            </svg>
                        </div>
                    </div>

                    <div class="dashboard-line-labels" aria-label="Eje horizontal: fechas">
                        @foreach ($dailyRows as $row)
                            <span>{{ $row['label'] }}</span>
                        @endforeach
                    </div>

                    <div class="row g-2 mt-3">
                        @php
                            $weeklyLeads = array_sum(array_map(static fn (array $row): int => (int) ($row['leads_count'] ?? 0), $dailyRows));
                            $weeklyOpportunities = array_sum(array_map(static fn (array $row): int => (int) ($row['opportunities_count'] ?? 0), $dailyRows));
                            $weeklyActivities = array_sum(array_map(static fn (array $row): int => (int) ($row['activities_count'] ?? 0), $dailyRows));
                        @endphp
                        <div class="col-4"><div class="dashboard-mini-stat text-primary"><strong>{{ $weeklyLeads }}</strong><span>Prospectos</span></div></div>
                        <div class="col-4"><div class="dashboard-mini-stat text-success"><strong>{{ $weeklyOpportunities }}</strong><span>Oportunid.</span></div></div>
                        <div class="col-4"><div class="dashboard-mini-stat text-warning"><strong>{{ $weeklyActivities }}</strong><span>Actividades</span></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card h-100 dashboard-chart-card" data-testid="dashboard-prospectos-origen">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <p class="card-text small text-uppercase text-secondary mb-1">Mes actual</p>
                            <h3 class="h5 mb-0">Prospectos por origen</h3>
                        </div>
                        <span class="dashboard-kpi-icon dashboard-kpi-icon-green" aria-hidden="true"><i class="bi bi-bar-chart-steps"></i></span>
                    </div>

                    @if ($sourceTotal > 0)
                        <div class="dashboard-vertical-bars" role="img" aria-label="Prospectos del mes agrupados por origen">
                            @foreach ($sourceRows as $row)
                                @php
                                    $sourceCount = (int) ($row['count'] ?? 0);
                                    $sourcePercent = max(8, (int) round(($sourceCount / $maxSourceCount) * 100));
                                @endphp
                                    <div class="dashboard-vertical-bar-item">
                                        <span class="dashboard-vertical-bar-value">{{ $sourceCount }}</span>
                                        <div class="dashboard-vertical-bar-track">
                                            <svg class="dashboard-vertical-bar-svg" viewBox="0 0 24 100" preserveAspectRatio="none" aria-hidden="true">
                                                <rect class="dashboard-vertical-bar-fill" x="0" y="{{ 100 - $sourcePercent }}" width="24" height="{{ $sourcePercent }}" rx="8"></rect>
                                            </svg>
                                        </div>
                                        <span class="dashboard-vertical-bar-label" title="{{ $row['name'] }}">{{ \Illuminate\Support\Str::limit($row['name'], 12) }}</span>
                                    </div>
                            @endforeach
                        </div>
                    @else
                        <div class="dashboard-empty-visual text-center text-secondary py-4">
                            <i class="bi bi-graph-up fs-2 d-block mb-2" aria-hidden="true"></i>
                            <p class="mb-0 small">Aún no hay prospectos registrados este mes.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-3">
            <div class="card h-100 dashboard-chart-card" data-testid="dashboard-agenda-timeline">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <p class="card-text small text-uppercase text-secondary mb-1">Agenda</p>
                            <h3 class="h5 mb-0">Timeline comercial</h3>
                        </div>
                        <span class="dashboard-kpi-icon dashboard-kpi-icon-orange" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
                    </div>

                    @php $reuniones = $proximas_reuniones ?? []; @endphp
                    @forelse ($reuniones as $reunion)
                        <div class="dashboard-timeline-item">
                            <span class="dashboard-timeline-marker" aria-hidden="true"></span>
                            <div>
                                <p class="fw-semibold mb-1">{{ $reunion['title'] }}</p>
                                <p class="text-secondary small mb-0">{{ $shortDate($reunion['scheduled_at'] ?? null) }} · {{ $reunion['owner'] ?? 'Sin responsable' }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="dashboard-empty-visual text-center text-secondary py-4">
                            <i class="bi bi-calendar2-check fs-2 d-block mb-2" aria-hidden="true"></i>
                            <p class="mb-0 small">No hay reuniones próximas en agenda.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ===================================================================
         ROW 5 — próximas reuniones (RF-DASH-001)
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
                        <th>Rendimiento</th>
                        <th class="text-end">Monto ganado</th>
                    </tr>
                </x-slot:headers>
                <x-slot:rows>
                        @forelse ($salesRows as $row)
                            @php
                                $salesCount = (int) ($row['won_count'] ?? 0);
                                $salesPercent = max(4, (int) round(($salesCount / $maxSalesCount) * 100));
                            @endphp
                            <tr>
                                <td>{{ $row['owner'] }}</td>
                                <td>{{ $row['currency_code'] }}</td>
                                <td class="text-end">{{ $salesCount }}</td>
                                <td>
                                    <div class="dashboard-bar-track dashboard-bar-track-sm" role="img" aria-label="{{ $row['owner'] }}: {{ $salesCount }} ventas ganadas">
                                        <svg class="dashboard-bar-svg" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
                                            <rect class="dashboard-bar-fill dashboard-bar-fill-success" x="0" y="0" width="{{ $salesPercent }}" height="8" rx="4"></rect>
                                        </svg>
                                    </div>
                                </td>
                                <td class="text-end">{{ $money((float) $row['won_amount']) }}</td>
                            </tr>
                        @empty
                            @include('dashboard._empty_row', ['colspan' => 5, 'slot' => 'Aún no hay ventas ganadas este mes.'])
                        @endforelse
                </x-slot:rows>
            </x-table>
        </div>
    </div>
@endsection