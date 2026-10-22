@extends('layouts.app')
@section('title', 'Dashboard de soporte')
@section('page-title', 'Dashboard de soporte')
@section('content')
    <form method="GET" class="card card-body mb-3"><div class="row g-2">
        <div class="col-md-2"><label class="form-label">Desde</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div><div class="col-md-2"><label class="form-label">Hasta</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
        @foreach (['customer_id'=>'Cliente','responsible_id'=>'Responsable','team_id'=>'Equipo','type_id'=>'Tipo','category_id'=>'Categoría','priority_id'=>'Prioridad','status_id'=>'Estado'] as $field => $label)
            @php $items = ['customer_id' => $customers, 'responsible_id' => $users, 'team_id' => $teams, 'type_id' => $types, 'category_id' => $categories, 'priority_id' => $priorities, 'status_id' => $statuses][$field]; @endphp
            <div class="col-md-2"><label class="form-label">{{ $label }}</label><select name="{{ $field }}" class="form-select"><option value="">Todos</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(($filters[$field] ?? '') == $item->id)>{{ $item->name ?? $item->code }}</option>@endforeach</select></div>
        @endforeach
        <div class="col-md-2"><label class="form-label">Modalidad</label><select name="modality" class="form-select"><option value="">Todas</option>@foreach(['virtual'=>'Virtual','presential'=>'Presencial','phone'=>'Teléfono','not_applicable'=>'No aplica'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['modality'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-12"><button class="btn btn-primary">Filtrar</button><a class="btn btn-outline-secondary" href="{{ route('support.dashboard') }}">Limpiar</a><a class="btn btn-outline-success" href="{{ route('support.export', request()->query()) }}">Exportar CSV</a></div>
    </div></form>
    @php
        $duration = function (float $seconds, int $count): string {
            if ($count === 0) {
                return 'Sin datos';
            }
            $minutes = intdiv((int) round($seconds), 60);
            return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
        };
        $counts = $metrics['counts'];
        $kpis = [
            ['Prom. primera respuesta', $duration($metrics['average_first_response_seconds'], $counts['first_response']), 'Desde la creación hasta la primera respuesta.'],
            ['Prom. resolución', $duration($metrics['average_resolution_seconds'], $counts['resolved']), 'Desde la creación hasta la resolución.'],
            ['Prom. tiempo efectivo', $duration($metrics['average_effective_resolution_seconds'], $counts['resolved']), 'Resolución sin contar el tiempo en espera del cliente.'],
            ['Prom. cierre', $duration($metrics['average_closure_seconds'], $counts['closed']), 'Desde la creación hasta el cierre.'],
            ['Prom. por ciclo de atención', $duration($metrics['average_cycle_seconds'], $counts['cycles']), 'Duración de cada ciclo resuelto (incluye reaperturas).'],
            ['Prom. efectivo por ciclo', $duration($metrics['average_cycle_effective_seconds'], $counts['cycles']), 'Ciclos resueltos sin contar la espera del cliente.'],
            ['Reabiertos', $counts['ever_resolved'] === 0 ? 'Sin datos' : number_format($metrics['reopened_percent'], 2).' %', $counts['reopened'].' de '.$counts['ever_resolved'].' tickets resueltos alguna vez.'],
            ['Resueltos dentro del objetivo', $metrics['resolved_within_target_percent'] === null ? 'Sin tiempos objetivo configurados' : number_format($metrics['resolved_within_target_percent'], 2).' %', 'Requiere tiempos objetivo por prioridad.'],
        ];
        $observationLabels = ['pending' => 'Pendientes', 'lifted' => 'Levantadas', 'validated' => 'Validadas'];
    @endphp
    @if ($metrics['total'] === 0)
        <div class="card"><div class="card-body text-center text-secondary py-5" data-testid="support-dashboard-empty">
            <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
            <p class="mb-1 fw-medium">No hay tickets para los filtros seleccionados.</p>
            <p class="mb-0 small">Ajuste los filtros o registre tickets para ver indicadores.</p>
        </div></div>
    @else
        <div class="row g-3">
            <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">Tickets recibidos</div><div class="h3 mb-0">{{ $metrics['total'] }}</div></div></div></div>
            @foreach ($kpis as [$label, $value, $hint])
                <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ $label }}</div><div class="h5 mb-1">{{ $value }}</div><div class="small text-secondary">{{ $hint }}</div></div></div></div>
            @endforeach
        </div>
        <div class="row g-3 mt-1">
            @foreach (['by_state' => 'Por estado', 'by_priority' => 'Por prioridad', 'by_type' => 'Por tipo', 'by_responsible' => 'Por responsable'] as $key => $title)
                <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-header"><h2 class="h6 mb-0">{{ $title }}</h2></div><div class="card-body p-0"><table class="table mb-0"><tbody>
                    @forelse ($metrics[$key] as $label => $count)<tr><td>{{ $label }}</td><td class="text-end">{{ $count }}</td></tr>@empty<tr><td class="text-muted">Sin datos</td></tr>@endforelse
                </tbody></table></div></div></div>
            @endforeach
            <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-header"><h2 class="h6 mb-0">Observaciones</h2></div><div class="card-body p-0"><table class="table mb-0"><tbody>
                @foreach ($metrics['observations'] as $state => $count)<tr><td>{{ $observationLabels[$state] ?? $state }}</td><td class="text-end">{{ $count }}</td></tr>@endforeach
            </tbody></table></div></div></div>
        </div>
    @endif
@endsection
