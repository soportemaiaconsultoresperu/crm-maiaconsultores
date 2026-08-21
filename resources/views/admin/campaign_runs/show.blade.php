@extends('layouts.app')

@section('title', "Ejecución {$run->code}")
@section('page-title', "Ejecución: {$run->name}")

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <code class="fs-5">{{ $run->code }}</code>
            <h2 class="mb-0">{{ $run->name }}</h2>
        </div>
        <div class="btn-group">
            @if ($run->status === 'draft')
                @can('schedule', $run)
                    <form method="POST" action="{{ route('admin.campaign_runs.schedule', $run) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-success"><i class="bi bi-play"></i> Programar</button>
                    </form>
                @endcan
            @endif
            @if (in_array($run->status, ['running', 'scheduled', 'paused'], true))
                @if ($run->status === 'paused')
                    @can('start', $run)
                        <form method="POST" action="{{ route('admin.campaign_runs.resume', $run) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-success"><i class="bi bi-play"></i> Reanudar</button>
                        </form>
                    @endcan
                @else
                    @can('pause', $run)
                        <form method="POST" action="{{ route('admin.campaign_runs.pause', $run) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-warning"><i class="bi bi-pause"></i> Pausar</button>
                        </form>
                    @endcan
                @endif
                @can('reschedule', $run)
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reschedule-modal">
                        <i class="bi bi-calendar-event"></i> Reprogramar
                    </button>
                @endcan
            @endif
            @can('complete', $run)
                @if (in_array($run->status, ['running', 'paused'], true))
                    <form method="POST" action="{{ route('admin.campaign_runs.complete', $run) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success"><i class="bi bi-check2"></i> Cerrar</button>
                    </form>
                @endcan
            @endcan
            @can('cancel', $run)
                @if (! in_array($run->status, ['completed', 'cancelled'], true))
                    <x-swal-confirm
                        :action="route('admin.campaign_runs.cancel', $run)"
                        method="POST"
                        title="¿Cancelar esta ejecución?"
                        text="La ejecución {{ $run->code }} quedará cancelada. Los items pendientes no se ejecutarán."
                        type="warning"
                        confirm-text="Sí, cancelar"
                        input="textarea"
                        input-name="reason"
                        input-label="Motivo"
                        input-required="true"
                        input-placeholder="Indique el motivo de la cancelación…"
                        button-class="btn-outline-danger">
                        <i class="bi bi-x-circle me-1"></i> Cancelar
                    </x-swal-confirm>
                @endcan
            @endcan
        </div>
    </div>

    @include('admin.campaign_runs.partials._kpis')

    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">Matriz de seguimiento</h3></div>
        <div class="card-body">
            @php($steps = $run->steps()->orderBy('order')->get())
            @if ($steps->isEmpty())
                <p class="text-secondary">Esta ejecución no tiene pasos.</p>
            @else
                <div class="table-responsive" style="max-height: 70vh; overflow: auto;">
                    <table class="table table-bordered table-sm align-middle" data-testid="campaign-matrix">
                        <thead class="table-light">
                            <tr>
                                <th style="position: sticky; left: 0; background: #f8f9fa; z-index: 2;">Contacto</th>
                                @foreach ($steps as $step)
                                    <th class="text-center">
                                        <small>{{ $step->title }}</small><br>
                                        <small class="text-secondary">Día {{ $step->day_offset }} @if ($step->scheduled_time) · {{ $step->scheduled_time }} @endif</small>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($participants as $participant)
                                <tr>
                                    <td style="position: sticky; left: 0; background: white;">
                                        <strong>{{ $participant->display_name }}</strong><br>
                                        <small class="text-secondary">{{ $participant->company_name ?? '' }}</small>
                                    </td>
                                    @foreach ($steps as $step)
                                        @php($item = $items->firstWhere(fn ($i) => $i->step_id === $step->id && $i->participant_id === $participant->id))
                                        <td class="text-center">
                                            @if ($item)
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#item-modal-{{ $item->id }}" class="text-decoration-none">
                                                    @switch($item->status)
                                                        @case('completed') <span class="text-success">✓</span> @break
                                                        @case('in_process') <span class="text-primary">▶</span> @break
                                                        @case('overdue') <span class="text-danger">✗</span> @break
                                                        @case('cancelled') <span class="text-danger">⊘</span> @break
                                                        @case('not_applicable') <span class="text-secondary">—</span> @break
                                                        @default <span class="text-warning">○</span>
                                                    @endswitch
                                                </a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
</tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @foreach ($items as $item)
        @include('admin.campaign_runs.partials._item_modal', [
            'item' => $item,
            'participant' => $item->participant,
            'step' => $item->step,
        ])
    @endforeach

    @can('reschedule', $run)
        @include('admin.campaign_runs.partials._reschedule_modal', ['run' => $run])
    @endcan
@endsection
