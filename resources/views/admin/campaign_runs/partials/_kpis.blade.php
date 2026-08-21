@php
    $metrics = $metrics ?? ['total' => 0, 'pending' => 0, 'in_process' => 0, 'completed' => 0, 'overdue' => 0, 'cancelled' => 0, 'not_applicable' => 0, 'progress' => 0];
@endphp

<div class="row g-3 mb-3" data-testid="campaign-kpis">
    <div class="col-sm-6 col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <p class="h4 mb-0">{{ $metrics['total'] ?? 0 }}</p>
                <small class="text-secondary">Total items</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card text-center border-warning">
            <div class="card-body">
                <p class="h4 mb-0 text-warning">{{ ($metrics['pending'] ?? 0) + ($metrics['in_process'] ?? 0) }}</p>
                <small class="text-secondary">Pendientes + En proceso</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card text-center border-success">
            <div class="card-body">
                <p class="h4 mb-0 text-success">{{ $metrics['completed'] ?? 0 }}</p>
                <small class="text-secondary">Realizadas</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <?php $progress = $metrics['progress'] ?? 0; ?>
                <p class="h4 mb-0"><?= $progress ?>%</p>
                <small class="text-secondary">Avance</small>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar" role="progressbar" style="width: <?= $progress ?>%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>
