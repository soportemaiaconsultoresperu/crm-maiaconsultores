@props(['type' => 'info', 'dismissible' => false])

@php
    $classMap = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'danger' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
    ];
@endphp

<div class="alert {{ $classMap[$type] ?? 'alert-info' }} @if ($dismissible) alert-dismissible fade show @endif"
     role="alert" {{ $attributes }}>
    {{ $slot }}
    @if ($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    @endif
</div>
