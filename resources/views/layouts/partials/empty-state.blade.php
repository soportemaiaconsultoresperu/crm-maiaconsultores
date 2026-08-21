{{--
    Empty-state placeholder for lists without records.
    Usage: @include('layouts.partials.empty-state', ['message' => 'No hay prospectos registrados.'])
--}}
<div class="text-center text-secondary py-5" data-testid="empty-state">
    <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
    <p class="mb-1 fw-medium">{{ $message ?? 'Sin registros.' }}</p>
    @isset($hint)
        <p class="small mb-0">{{ $hint }}</p>
    @endisset
    @isset($slot)
        {{ $slot }}
    @endisset
</div>
