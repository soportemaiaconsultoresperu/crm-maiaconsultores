{{--
    Pagination wrapper. Uses the settings.pagination_size default (25)
    at the query level; rendering comes from Laravel's Bootstrap 5
    pagination views (Paginator::useBootstrapFive in AppServiceProvider).

    Usage: @include('layouts.partials.pagination', ['paginator' => $records])
--}}
@if (isset($paginator) && $paginator->hasPages())
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3">
        <p class="small text-secondary mb-0">
            Mostrando {{ $paginator->firstItem() }} – {{ $paginator->lastItem() }}
            de {{ $paginator->total() }} registro(s).
        </p>
        <nav aria-label="Paginación">
            {{ $paginator->onEachSide(1)->links() }}
        </nav>
    </div>
@endif
