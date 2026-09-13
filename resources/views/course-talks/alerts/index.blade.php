@extends('layouts.app')

@section('title', 'Alertas de entrega')
@section('page-title', 'Alertas de entrega de Cursos y charlas')

@section('content')
    @php
        // Presentation-only maps. Which follow-up is outstanding, which one is
        // overdue and what discarding closes are decided by CourseAlertService;
        // whether a document can still be delivered is decided by
        // CourseDocumentDeliveryService. This surface only renders those values in
        // Spanish, exactly as the edition document screens do.
        $activityTypeMeta = [
            'course' => 'Curso',
            'talk' => 'Charla',
        ];
        $documentTypeMeta = [
            'approval_certificate' => 'Certificado de aprobación',
            'participation_constancy' => 'Constancia de participación',
            'talk_certificate' => 'Certificado de charla',
            'factura' => 'Factura',
            'boleta' => 'Boleta',
            'recibo' => 'Recibo',
        ];
        $kindMeta = [
            'academico' => ['Documento académico', 'text-bg-primary'],
            'comercial' => ['Comprobante', 'text-bg-info'],
        ];
        $deliveryStatusMeta = [
            'pending' => ['Entrega pendiente', 'text-bg-secondary'],
            'sent' => ['Enviado', 'text-bg-success'],
            'failed' => ['Entrega fallida', 'text-bg-danger'],
            'discarded' => ['Descartado', 'text-bg-light'],
        ];
        $channelMeta = [
            'mail' => 'Correo',
            'whatsapp' => 'WhatsApp',
        ];
        $filterLabels = [
            'activity_type' => 'Tipo de actividad',
            'edition_id' => 'Edición',
            'participant_id' => 'Participante',
            'responsible_user_id' => 'Responsable',
            'document_type' => 'Tipo de documento',
            'delivery_status' => 'Estado de entrega',
            'channel' => 'Canal del último intento',
            'date_from' => 'Emitido desde',
            'date_to' => 'Emitido hasta',
        ];
        $fallbackMeta = static fn (string $value): array => [str_replace('_', ' ', $value), 'text-bg-secondary'];
        $filterValue = static fn (string $key): string => (string) (old($key, $filters[$key] ?? ''));
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary">Volver a las actividades</a>
        <p class="text-secondary small mb-0">Se listan las entregas pendientes y fallidas de documentos académicos y comprobantes vigentes.</p>
    </div>

    {{-- Aggregate counters from the alert domain, not a count of the rows below:
         the module's workload stays visible while the list is filtered. --}}
    <div class="row g-3 mb-3" data-testid="course-talks-alerts-counts">
        <div class="col-6 col-md-4">
            <div class="card h-100 dashboard-kpi-card">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Entregas pendientes</p>
                    <p class="card-text h3 mb-0" data-testid="course-talks-alerts-pending-count">{{ $pendingCount }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card h-100 dashboard-kpi-card">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Entregas vencidas</p>
                    <p class="card-text h3 mb-0 text-danger" data-testid="course-talks-alerts-overdue-count">{{ $overdueCount }}</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100 dashboard-kpi-card">
                <div class="card-body">
                    <p class="card-text small text-uppercase text-secondary mb-1">Resultado del filtro</p>
                    <p class="card-text h3 mb-0">{{ count($rows) }}</p>
                </div>
            </div>
        </div>
    </div>

    @if ($invalidFilters !== [])
        <x-alert type="warning" data-testid="course-talks-alerts-filters-invalid">
            <p class="mb-1">Estos filtros no tienen un valor válido y no encontraron ninguna entrega:</p>
            <ul class="mb-1">
                @foreach ($invalidFilters as $invalidFilter)
                    <li>{{ $filterLabels[$invalidFilter] ?? $invalidFilter }}</li>
                @endforeach
            </ul>
            <p class="mb-0">Borre o corrija el filtro para volver a ver la lista completa.</p>
        </x-alert>
    @endif

    @if ($errors->any())
        <x-alert type="error" data-testid="course-talks-alerts-errors">
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    {{-- Plain HTML GET form on purpose: the filters are a query-string contract,
         and an interpolation inside a Blade component's attributes would not be
         evaluated. The entity filters offer the values present in the outstanding
         set, so any offered filter matches at least one row; the "Todas" option
         clears it. --}}
    <form method="GET" action="{{ route('course-talks.alerts.index') }}" class="card card-body mb-3" data-testid="course-talks-alerts-filters">
        <div class="row g-2">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-activity-type">Tipo de actividad</label>
                <select class="form-select form-select-sm" id="alerts-activity-type" name="activity_type">
                    <option value="">Todas</option>
                    @foreach ($activityTypeMeta as $value => $label)
                        <option value="{{ $value }}" @selected($filterValue('activity_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-edition">Edición</label>
                <select class="form-select form-select-sm" id="alerts-edition" name="edition_id">
                    <option value="">Todas</option>
                    @foreach ($editionOptions as $value => $label)
                        <option value="{{ $value }}" @selected((string) $filterValue('edition_id') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-participant">Participante</label>
                <select class="form-select form-select-sm" id="alerts-participant" name="participant_id">
                    <option value="">Todos</option>
                    @foreach ($participantOptions as $value => $label)
                        <option value="{{ $value }}" @selected((string) $filterValue('participant_id') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-responsible">Responsable</label>
                <select class="form-select form-select-sm" id="alerts-responsible" name="responsible_user_id">
                    <option value="">Todos</option>
                    @foreach ($responsibleOptions as $value => $label)
                        <option value="{{ $value }}" @selected((string) $filterValue('responsible_user_id') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-document-type">Tipo de documento</label>
                <select class="form-select form-select-sm" id="alerts-document-type" name="document_type">
                    <option value="">Todos</option>
                    @foreach ($documentTypeMeta as $value => $label)
                        <option value="{{ $value }}" @selected($filterValue('document_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-delivery-status">Estado de entrega</label>
                <select class="form-select form-select-sm" id="alerts-delivery-status" name="delivery_status">
                    <option value="">Todos</option>
                    @foreach ($deliveryStatusMeta as $value => $meta)
                        <option value="{{ $value }}" @selected($filterValue('delivery_status') === $value)>{{ $meta[0] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-channel">Canal del último intento</label>
                <select class="form-select form-select-sm" id="alerts-channel" name="channel">
                    <option value="">Todos</option>
                    @foreach ($channelMeta as $value => $label)
                        <option value="{{ $value }}" @selected($filterValue('channel') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-date-from">Emitido desde</label>
                <input type="date" class="form-control form-control-sm" id="alerts-date-from" name="date_from" value="{{ $filterValue('date_from') }}">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small" for="alerts-date-to">Emitido hasta</label>
                <input type="date" class="form-control form-control-sm" id="alerts-date-to" name="date_to" value="{{ $filterValue('date_to') }}">
            </div>
            <div class="col-12 col-md-6 col-xl-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                <a href="{{ route('course-talks.alerts.index') }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            </div>
        </div>
    </form>

    <x-table title="Entregas pendientes y fallidas" data-testid="course-talks-alerts-table">
        @slot('headers')
            <tr>
                <th scope="col">Documento</th>
                <th scope="col">Actividad y edición</th>
                <th scope="col">Participante y responsable</th>
                <th scope="col">Estado de entrega</th>
                <th scope="col">Último intento</th>
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot
        @slot('rows')
            @forelse ($rows as $row)
                @php
                    $kind = $kindMeta[$row['kind']];
                    $deliveryMeta = $deliveryStatusMeta[$row['delivery_status']] ?? $fallbackMeta($row['delivery_status']);
                @endphp
                <tr data-testid="course-talks-alert-{{ $row['kind'] }}-{{ $row['id'] }}">
                    <td>
                        <span class="badge {{ $kind[1] }}">{{ $kind[0] }}</span>
                        <div class="fw-semibold">{{ $row['reference'] }}</div>
                        <div class="small text-secondary">{{ $documentTypeMeta[$row['document_type']] ?? $row['document_type'] }}</div>
                    </td>
                    <td>
                        {{ $row['activity'] ?? '—' }}
                        <div class="small text-secondary">
                            {{ $row['activity_type'] ? ($activityTypeMeta[$row['activity_type']] ?? $row['activity_type']) : '—' }}
                            · {{ $row['edition_label'] ?? '—' }}
                        </div>
                    </td>
                    <td>
                        {{ $row['participant_label'] ?? '—' }}
                        <div class="small text-secondary">Responsable: {{ $row['responsible_label'] ?? 'Sin responsable' }}</div>
                    </td>
                    <td>
                        <span class="badge {{ $deliveryMeta[1] }}">{{ $deliveryMeta[0] }}</span>
                        @if ($row['overdue'])
                            <span class="badge text-bg-danger" data-testid="course-talks-alert-overdue-{{ $row['kind'] }}-{{ $row['id'] }}">Vencida</span>
                        @else
                            <span class="badge text-bg-warning" data-testid="course-talks-alert-pending-{{ $row['kind'] }}-{{ $row['id'] }}">En plazo</span>
                        @endif
                        <div class="small text-secondary">Fecha de emisión: {{ $row['anchor'] }}</div>
                        @if ($row['deliverable'])
                            <div class="small text-success" data-testid="course-talks-alert-deliverable-{{ $row['kind'] }}-{{ $row['id'] }}">
                                Archivo disponible: la entrega puede intentarse.
                            </div>
                        @else
                            <div class="small text-danger" data-testid="course-talks-alert-undeliverable-{{ $row['kind'] }}-{{ $row['id'] }}">
                                No entregable: falta el archivo privado. Restáurelo para poder enviarlo.
                            </div>
                        @endif
                    </td>
                    <td data-testid="course-talks-alert-channel-{{ $row['kind'] }}-{{ $row['id'] }}" data-channel="{{ $row['channel'] }}">
                        {{ $row['channel'] ? ($channelMeta[$row['channel']] ?? $row['channel']) : 'Sin intentos registrados' }}
                        <div class="small text-secondary" data-testid="course-talks-alert-history-{{ $row['kind'] }}-{{ $row['id'] }}" data-attempts="{{ $row['history_count'] }}">
                            Historial: {{ $row['history_count'] }} intento(s)
                            @if ($row['channel'])
                                · Último: {{ $row['last_attempt_at'] ?? '—' }} ({{ $row['last_attempt_status'] }})
                            @endif
                        </div>
                        @if ($row['last_error'])
                            <div class="small text-danger">Error: {{ $row['last_error'] }}</div>
                        @endif
                    </td>
                    <td class="text-end">
                        @if ($row['can_discard'])
                            {{-- Descartar cierra el seguimiento sin tocar el documento, y
                                 exige un motivo: el servicio es el que lo exige y su
                                 mensaje se muestra arriba. El control se ofrece solo a
                                 quien tiene la habilidad que la ruta exige. --}}
                            <form method="POST" action="{{ $row['discard_url'] }}" class="d-flex flex-wrap gap-1 justify-content-end" data-testid="course-talks-alert-discard-form-{{ $row['kind'] }}-{{ $row['id'] }}">
                                @csrf
                                <label class="visually-hidden" for="alert-discard-reason-{{ $row['kind'] }}-{{ $row['id'] }}">Motivo para descartar la alerta de {{ $row['reference'] }}</label>
                                <input type="text" class="form-control form-control-sm w-auto" id="alert-discard-reason-{{ $row['kind'] }}-{{ $row['id'] }}" name="reason" maxlength="500" required placeholder="Motivo (obligatorio)" value="{{ old('reason') }}">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Descartar</button>
                            </form>
                        @else
                            <span class="text-secondary small">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-secondary py-4" data-testid="course-talks-alerts-empty">
                        No se encontraron entregas pendientes con los filtros aplicados.
                    </td>
                </tr>
            @endforelse
        @endslot
    </x-table>
@endsection
