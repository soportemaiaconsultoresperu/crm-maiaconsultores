{{--
    Task counters of a project progress summary.

    Expected data: $progress (InternalProjectProgressService::projectProgress()).
--}}
<ul class="list-inline small mb-0" data-testid="project-counts">
    <li class="list-inline-item">Total: <strong data-count="total">{{ $progress['total'] }}</strong></li>
    <li class="list-inline-item text-success">Terminadas: <strong data-count="terminadas">{{ $progress['terminadas'] }}</strong></li>
    <li class="list-inline-item text-primary">En curso: <strong data-count="en_curso">{{ $progress['en_curso'] }}</strong></li>
    <li class="list-inline-item text-warning-emphasis">Bloqueadas: <strong data-count="bloqueadas">{{ $progress['bloqueadas'] }}</strong></li>
    <li class="list-inline-item text-danger">Atrasadas: <strong data-count="atrasadas">{{ $progress['atrasadas'] }}</strong></li>
</ul>
