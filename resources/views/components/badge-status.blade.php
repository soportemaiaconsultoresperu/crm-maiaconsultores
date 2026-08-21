@props(['status' => ''])

@php
    // Generic status → Bootstrap 5 badge mapping (Spanish labels).
    $badges = [
        'active' => ['Activo', 'text-bg-success'],
        'inactive' => ['Inactivo', 'text-bg-secondary'],
        'pending' => ['Pendiente', 'text-bg-warning'],
        'in_process' => ['En proceso', 'text-bg-info'],
        'overdue' => ['Vencida', 'text-bg-danger'],
        'completed' => ['Completada', 'text-bg-success'],
        'cancelled' => ['Cancelada', 'text-bg-secondary'],
        'won' => ['Ganada', 'text-bg-success'],
        'lost' => ['Perdida', 'text-bg-danger'],
        'open' => ['Abierta', 'text-bg-primary'],
        'draft' => ['Borrador', 'text-bg-secondary'],
        'sent' => ['Enviada', 'text-bg-info'],
        'accepted' => ['Aceptada', 'text-bg-success'],
        'rejected' => ['Rechazada', 'text-bg-danger'],
        'expired' => ['Expirada', 'text-bg-warning'],
    ];
    [$label, $class] = $badges[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'text-bg-secondary'];
@endphp

<span class="badge {{ $class }}" data-status="{{ $status }}" {{ $attributes }}>{{ $label }}</span>
