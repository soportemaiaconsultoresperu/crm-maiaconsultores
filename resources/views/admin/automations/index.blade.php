@extends('layouts.app')

@section('title', 'Automatizaciones')
@section('page-title', 'Automatizaciones')

@section('content')
    <p class="text-muted">
        Listado de reglas registradas en el motor de automatizaciones (B12).
        @if ($trashView ?? false)
            Estás viendo la <strong>papelera</strong> — sólo se listan reglas con
            <code>deleted_at</code> no nulo. Las acciones en estas filas sólo
            aplican a <em>restaurar</em>; las reglas siguen siendo administrables
            después de la restauración.
        @else
            La UI completa de creación y edición entra con el bloque B12-UI.
        @endif
    </p>

    {{-- Tabs: Activas | Papelera (CRUD-07 / CRUD-08, UI-07) --}}
    @unless ($trashView ?? false)
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('admin.automations.index') }}"
               class="btn btn-sm btn-primary"
               aria-current="page">
                <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>
                Activas
            </a>
            <a href="{{ route('admin.automations.trash') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-trash me-1" aria-hidden="true"></i>
                Papelera
            </a>
        </div>
    @else
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('admin.automations.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>
                Activas
            </a>
            <a href="{{ route('admin.automations.trash') }}"
               class="btn btn-sm btn-warning"
               aria-current="page">
                <i class="bi bi-trash me-1" aria-hidden="true"></i>
                Papelera
            </a>
        </div>
    @endunless

    <x-table title="{{ $trashView ?? false ? 'Reglas en papelera' : 'Reglas' }}">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Trigger</th>
                <th>Modo</th>
                <th>Activa</th>
                <th>Ejecuciones</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($rules as $rule)
                <tr>
                    <td>{{ $rule->id }}</td>
                    <td>{{ $rule->name }}</td>
                    <td><code>{{ $rule->trigger_event }}</code></td>
                    <td>
                        <span class="badge bg-{{ $rule->isLiveMode() ? 'success' : 'secondary' }}">
                            {{ $rule->mode }}
                        </span>
                    </td>
                    <td>
                        @if ($trashView ?? false)
                            <span class="badge bg-secondary">En papelera</span>
                        @else
                            @can('automations.manage')
                                {{-- CRUD-05 inline toggle (Stage 2A). The form
                                     posts + method-spoofs PATCH to
                                     admin.automations.toggle; the controller
                                     flips is_active inside DB::transaction()
                                     and returns a JSON envelope of
                                     { ok, is_active, id }. --}}
                                <form method="POST"
                                      action="{{ route('admin.automations.toggle', $rule) }}"
                                      class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="btn btn-sm btn-{{ $rule->is_active ? 'success' : 'outline-secondary' }}"
                                            title="{{ $rule->is_active ? 'Desactivar regla' : 'Activar regla' }}"
                                            aria-label="{{ $rule->is_active ? 'Desactivar regla' : 'Activar regla' }}">
                                        <i class="bi {{ $rule->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}" aria-hidden="true"></i>
                                        {{ $rule->is_active ? 'Sí' : 'No' }}
                                    </button>
                                </form>
                            @else
                                <span class="badge bg-{{ $rule->is_active ? 'success' : 'secondary' }}">
                                    {{ $rule->is_active ? 'Sí' : 'No' }}
                                </span>
                            @endcan
                        @endif
                    </td>
                    <td>{{ $rule->executions_count ?? 0 }}</td>
                    <td class="text-end text-nowrap">
                        @if ($trashView ?? false)
                            {{-- CRUD-08 restore (Stage 2B) --}}
                            @can('automations.manage')
                                <form method="POST"
                                      action="{{ route('admin.automations.restore', ['id' => $rule->id]) }}"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            title="Restaurar regla">
                                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                                        Restaurar
                                    </button>
                                </form>
                            @endcan
                        @else
                            <a href="{{ route('admin.automations.show', $rule) }}" class="btn btn-sm btn-outline-primary">
                                Ver historial
                            </a>
                            {{-- CRUD-07 soft-delete (Stage 2B) --}}
                            @can('automations.manage')
                                <form method="POST"
                                      action="{{ route('admin.automations.destroy', $rule) }}"
                                      class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('¿Enviar la regla «{{ $rule->name }}» a la papelera?')"
                                            title="Enviar a papelera">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                        Papelera
                                    </button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        @if ($trashView ?? false)
                            No hay reglas en la papelera.
                        @else
                            No hay reglas registradas todavía.
                        @endif
                    </td>
                </tr>
            @endforelse

            @if (method_exists($rules, 'links'))
                <tr>
                    <td colspan="7">{{ $rules->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>
@endsection
