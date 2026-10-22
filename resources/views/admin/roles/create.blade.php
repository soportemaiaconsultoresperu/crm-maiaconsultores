@extends('layouts.app')

@section('title', 'Nuevo rol')
@section('page-title', 'Nuevo rol')

@section('content')
    <form method="GET" action="{{ route('admin.roles.create') }}" class="card mb-3" id="copy-from-form">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-6">
                <label for="copy_from" class="form-label">Copiar permisos de otro rol</label>
                <select name="copy_from" id="copy_from" class="form-select">
                    <option value="">Empezar sin permisos</option>
                    @foreach ($copyableRoles as $copyable)
                        <option value="{{ $copyable->id }}" @selected($copyFrom?->id === $copyable->id)>{{ ucfirst($copyable->name) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-files me-1" aria-hidden="true"></i> Copiar permisos
                </button>
            </div>
            <div class="col-12 form-text mt-1">
                @if ($copyFrom)
                    Se marcaron los permisos del rol «{{ ucfirst($copyFrom->name) }}». Ajústelos y guarde el nuevo rol.
                @else
                    Opcional: parta de un rol existente y ajuste solo lo que cambie.
                @endif
            </div>
        </div>
    </form>

    @include('admin.roles._form', [
        'action' => route('admin.roles.store'),
        'method' => 'POST',
        'submitLabel' => 'Crear rol',
        'cancelLabel' => 'Cancelar',
    ])
@endsection

@push('scripts')
    <script>
        (function () {
            'use strict';

            var select = document.getElementById('copy_from');
            if (select !== null) {
                select.addEventListener('change', function () {
                    document.getElementById('copy-from-form').submit();
                });
            }
        })();
    </script>
@endpush
