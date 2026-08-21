@extends('layouts.app')

@section('title', 'Auditoría — Regla #' . $rule->id)
@section('page-title', 'Auditoría — ' . $rule->name)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('admin.automations.show', $rule) }}"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Volver a la regla
        </a>
    </div>

    <dl class="row mb-4">
        <dt class="col-sm-2">Regla</dt>
        <dd class="col-sm-10">
            <code>#{{ $rule->id }}</code> · {{ $rule->name }}
            <x-test-mode-badge :mode="$rule->mode" extraClass="ms-1" />
        </dd>

        <dt class="col-sm-2">Trigger</dt>
        <dd class="col-sm-10"><code>{{ $rule->trigger_event }}</code></dd>

        <dt class="col-sm-2">Modo</dt>
        <dd class="col-sm-10">{{ $rule->mode }}</dd>
    </dl>

    {{-- SCN-AUDIT-01-A — dedicated Blade view (not JSON) for the audit feed. --}}
    @include('admin.automations.partials._audit_changes', ['auditEntries' => $entries])
@endsection
