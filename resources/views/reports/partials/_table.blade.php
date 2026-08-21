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
        @isset($filters)
            <p class="small text-secondary mb-0" data-testid="report-filters-applied">
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