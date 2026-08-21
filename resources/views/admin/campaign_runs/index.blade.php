@extends('layouts.app')

@section('title', 'Ejecuciones de campañas')
@section('page-title', 'Ejecuciones de campañas')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('create', App\Models\CampaignRun::class)
            <a href="{{ route('admin.campaign_runs.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Nueva ejecución
            </a>
        @endcan
    </div>

    <x-table title="Ejecuciones de campañas" data-testid="campaign-runs-table">
        @slot('headers')
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Plantilla</th>
                <th>Inicio</th>
                <th>Estado</th>
                <th>Avance</th>
                <th>Responsable</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($runs as $run)
                <tr data-testid="run-row">
                    <td><code>{{ $run->code }}</code></td>
                    <td><a href="{{ route('admin.campaign_runs.show', $run) }}">{{ $run->name }}</a></td>
                    <td>{{ $run->template->name ?? '—' }}</td>
                    <td>{{ $run->starts_at->format('d/m/Y') }}</td>
                    <td>
                        <span class="badge text-bg-{{ match($run->status) {
                            'draft' => 'secondary',
                            'scheduled' => 'info',
                            'running' => 'primary',
                            'paused' => 'warning',
                            'completed' => 'success',
                            'cancelled' => 'danger',
                            default => 'secondary',
                        } }}">
                            {{ ucfirst($run->status) }}
                        </span>
                    </td>
                    <td>
                        @if (! empty($run->progress_cache))
                            @php($progress = (int) ($run->progress_cache['progress'] ?? 0))
                            <div class="progress" style="height: 18px;">
                                <div class="progress-bar" role="progressbar" style="width: {{ $progress }}%;">
                                    {{ $progress }}%
                                </div>
                            </div>
                        @else
                            <span class="text-secondary">—</span>
                        @endif
                    </td>
                    <td>{{ $run->owner->name ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        @can('view', $run)
                            <a href="{{ route('admin.campaign_runs.show', $run) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> Ver
                            </a>
                        @endcan
                        @if ($run->status === 'draft')
                            @can('schedule', $run)
                                <form method="POST" action="{{ route('admin.campaign_runs.schedule', $run) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-play"></i> Programar
                                    </button>
                                </form>
                            @endcan
                        @endif
                        @can('cancel', $run)
                            @if (in_array($run->status, ['draft', 'scheduled', 'running', 'paused'], true))
                                <x-swal-confirm
                                    :action="route('admin.campaign_runs.cancel', $run)"
                                    method="POST"
                                    title="¿Cancelar esta ejecución?"
                                    :text="'La ejecución '.($run->code ?? '').' quedará cancelada. Los items pendientes no se ejecutarán.'"
                                    type="warning"
                                    confirm-text="Sí, cancelar"
                                    input="textarea"
                                    input-name="reason"
                                    input-label="Motivo"
                                    input-required="true"
                                    input-placeholder="Indique el motivo de la cancelación…"
                                    button-class="btn-sm btn-outline-danger">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </x-swal-confirm>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        @include('layouts.partials.empty-state', ['message' => 'Sin ejecuciones registradas.'])
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $runs])
        @endslot
    </x-table>
@endsection
