{{--
    Calendar navigation (RF-CAL-001..002). Renders:
    - view tabs (month | week | day | list)
    - prev / next / today anchor links
    - filters: owner, type, subject_type, status
--}}
@php
    $todayAnchor = now()->format('Y-m-d');
    $baseUrl = route('calendar.index');
    $currentParams = request()->only(['view', 'owner_id', 'type_id', 'subject_type', 'status']);
@endphp

<div class="card mb-3" data-testid="calendar-nav">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <ul class="nav nav-pills me-auto" data-testid="calendar-tabs">
                @foreach (['month' => 'Mes', 'week' => 'Semana', 'day' => 'Día', 'list' => 'Lista'] as $key => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $view === $key ? 'active' : '' }}"
                           href="{{ $baseUrl }}?{{ http_build_query(array_merge($currentParams, ['view' => $key, 'anchor' => $todayAnchor])) }}"
                           data-testid="tab-{{ $key }}">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>

            <div class="btn-group" role="group" aria-label="Navegación del calendario">
                <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($currentParams, ['anchor' => $prevAnchor->format('Y-m-d')])) }}"
                   class="btn btn-outline-secondary" data-testid="btn-prev">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
                <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($currentParams, ['anchor' => $todayAnchor])) }}"
                   class="btn btn-outline-secondary" data-testid="btn-today">Hoy</a>
                <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($currentParams, ['anchor' => $nextAnchor->format('Y-m-d')])) }}"
                   class="btn btn-outline-secondary" data-testid="btn-next">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>

        <form method="GET" action="{{ $baseUrl }}" class="row g-2 align-items-end" data-testid="calendar-filters">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="hidden" name="anchor" value="{{ $anchor->format('Y-m-d') }}">

            @if (! empty($owners) && (auth()->user()->can('activities.view.any') || auth()->user()->can('activities.view.team')))
                <div class="col-auto">
                    <select name="owner_id" class="form-select form-select-sm" aria-label="Responsable">
                        <option value="">Responsable</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}" @selected((string) request('owner_id') === (string) $owner->id)>{{ $owner->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-auto">
                <select name="type_id" class="form-select form-select-sm" aria-label="Tipo">
                    <option value="">Tipo</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected((string) request('type_id') === (string) $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
                <div class="col-auto">
                    <select name="subject_type" class="form-select form-select-sm" aria-label="Sujeto">
                        <option value="">Sujeto</option>
                        <option value="lead" @selected(request('subject_type') === 'lead')>Prospecto</option>
                        <option value="customer" @selected(request('subject_type') === 'customer')>Cliente</option>
                        <option value="opportunity" @selected(request('subject_type') === 'opportunity')>Oportunidad</option>
                    </select>
                </div>
            <div class="col-auto">
                <select name="status" class="form-select form-select-sm" aria-label="Estado">
                    <option value="">Estado</option>
                    <option value="pending" @selected(request('status') === 'pending')>Pendiente</option>
                    <option value="in_process" @selected(request('status') === 'in_process')>En proceso</option>
                    <option value="completed" @selected(request('status') === 'completed')>Completada</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelada</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                <a href="{{ $baseUrl }}?view={{ $view }}&anchor={{ $anchor->format('Y-m-d') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            </div>
        </form>
    </div>
</div>
