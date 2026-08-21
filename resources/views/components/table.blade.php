{{--
    Table wrapper with an optional search/filters slot.
    Slots:
      - filters: search inputs / selects above the table.
      - headers: <th> cells.
      - rows: <tr> rows (or an empty-state partial).
    Props: id (table id), empty (optional message forwarded to the
    empty-state partial when the rows slot is empty is decided by the page,
    not this component).
--}}
@props(['id' => null, 'title' => null])

<div class="card" data-testid="x-table">
    <div class="card-header">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
            @if ($title)
                <h3 class="card-title mb-0">{{ $title }}</h3>
            @endif
            <div class="filters-slot w-auto">
                {{ $filters ?? '' }}
            </div>
        </div>
    </div>

    <div class="card-body table-responsive p-0">
        <table id="{{ $id }}" class="table table-hover align-middle mb-0" {{ $attributes }}>
            <thead class="table-light">
                {{ $headers }}
            </thead>
            <tbody>
                {{ $rows ?? $slot }}
            </tbody>
        </table>
    </div>

    {{ $pagination ?? '' }}
</div>
