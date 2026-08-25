{{--
    Shared table partial for every report (RF-REP-001).

    Props:
      - $headings: list<string>  Column labels.
      - $rows:     list<list<mixed>> Pre-rendered tabular rows (one per data row).
      - $colspan:  optional colspan for the empty-state row (defaults to heading count).

    All cell values are escaped through `e()` so currency codes and amounts
    never inject HTML. Output is plain HTML tables — no chart libraries.
--}}
@php
    $headings = $headings ?? [];
    $rows = $rows ?? [];
    $colspan = $colspan ?? count($headings);
    $emptyMessage = $emptyMessage ?? 'Sin datos para los filtros aplicados.';
@endphp

<x-table id="report-table" :title="$title ?? null">
    <x-slot:filters>
        @php
            $filterKeys = $filterKeys ?? [];
            $filterOptions = $filterOptions ?? [];
        @endphp

        @if (! empty($filterKeys))
            <form method="GET" action="{{ route('reports.show', ['kind' => $kind]) }}" class="row g-2 align-items-end" data-testid="report-filters-form">
                @if (in_array('from', $filterKeys, true))
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="report-filter-from">Desde</label>
                        <input id="report-filter-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                @endif
                @if (in_array('to', $filterKeys, true))
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="report-filter-to">Hasta</label>
                        <input id="report-filter-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                @endif
                @if (in_array('owner_id', $filterKeys, true))
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="report-filter-owner">Responsable</label>
                        <select id="report-filter-owner" name="owner_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach (($filterOptions['owners'] ?? []) as $id => $name)
                                <option value="{{ $id }}" @selected((string) ($filters['owner_id'] ?? '') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('source_id', $filterKeys, true))
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="report-filter-source">Origen</label>
                        <select id="report-filter-source" name="source_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach (($filterOptions['sources'] ?? []) as $id => $name)
                                <option value="{{ $id }}" @selected((string) ($filters['source_id'] ?? '') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('status', $filterKeys, true))
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="report-filter-status">Estado</label>
                        <select id="report-filter-status" name="status" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach (($filterOptions['status'] ?? []) as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('currency', $filterKeys, true))
                    <div class="col-auto">
                        <label class="form-label small mb-1" for="report-filter-currency">Moneda</label>
                        <select id="report-filter-currency" name="currency" class="form-select form-select-sm">
                            <option value="">Todas</option>
                            @foreach (($filterOptions['currencies'] ?? []) as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['currency'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Aplicar</button>
                    <a href="{{ route('reports.show', ['kind' => $kind]) }}" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        @endif

        @isset($filters)
            <p class="small text-secondary mb-0 mt-2" data-testid="report-filters-applied">
                @if (empty($filters))
                    <span class="fw-medium">Filtros aplicados:</span> ninguno.
                @else
                    <span class="fw-medium">Filtros aplicados:</span>
                    @foreach ($filters as $key => $value)
                        <span class="badge text-bg-secondary me-1">{{ $key }} = {{ e($value) }}</span>
                    @endforeach
                @endif
            </p>
        @endisset
    </x-slot:filters>
    <x-slot:headers>
        <tr>
            @foreach ($headings as $heading)
                <th>{{ e($heading) }}</th>
            @endforeach
        </tr>
    </x-slot:headers>
    <x-slot:rows>
        @forelse ($rows as $row)
            <tr>
                @foreach ($row as $cell)
                    <td>{{ e($cell ?? '') }}</td>
                @endforeach
            </tr>
        @empty
            <tr class="text-secondary" data-testid="report-empty-row">
                <td colspan="{{ $colspan }}" class="text-center small py-3">{{ $emptyMessage }}</td>
            </tr>
        @endforelse
    </x-slot:rows>
</x-table>