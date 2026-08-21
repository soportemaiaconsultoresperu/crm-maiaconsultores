@extends('layouts.app')

@section('title', 'Plantillas de WhatsApp')
@section('page-title', 'Plantillas de WhatsApp')

@section('content')
    <p class="text-muted">
        Catálogo B14 — plantillas aprobadas por Meta, sincronizadas vía
        <code>whatsapp:sync-templates</code>. Solo se persisten las plantillas
        con <code>status='APPROVED'</code> (decisión 15c). El CRM no permite
        crear ni editar plantillas desde la interfaz (decisión 15d).
    </p>

    <x-table title="Plantillas">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Idioma</th>
                <th>Categoría</th>
                <th>Estado</th>
                <th>Cuenta</th>
                <th>Sincronizado</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($templates as $template)
                <tr>
                    <td>{{ $template->id }}</td>
                    <td><code>{{ $template->name }}</code></td>
                    <td>{{ $template->language }}</td>
                    <td>{{ $template->category ?: '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $template->status === 'approved' ? 'success' : ($template->status === 'rejected' ? 'danger' : 'secondary') }}">
                            {{ $template->status }}
                        </span>
                    </td>
                    <td>{{ $template->account?->display_name ?? '—' }}</td>
                    <td>{{ optional($template->synced_at)->format('Y-m-d H:i') ?: '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('whatsapp.templates.show', $template) }}"
                           class="btn btn-sm btn-outline-primary">
                            Ver
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        No hay plantillas sincronizadas. Ejecute
                        <code>php artisan whatsapp:sync-templates --all</code>
                        para sincronizar desde Meta.
                    </td>
                </tr>
            @endforelse

            @if (method_exists($templates, 'links'))
                <tr>
                    <td colspan="8">{{ $templates->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>

    <form method="GET" action="{{ route('whatsapp.templates.index') }}" class="mt-3">
        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0">Filtros</h3></div>
            <div class="card-body row g-2">
                <div class="col-md-3">
                    <label class="form-label" for="account_id">Cuenta</label>
                    <select name="account_id" id="account_id" class="form-select">
                        <option value="">Todas</option>
                        @foreach (\App\Models\WhatsApp\WhatsAppAccount::query()->orderBy('display_name')->get() as $account)
                            <option value="{{ $account->id }}" @selected((string) ($filters['account_id'] ?? '') === (string) $account->id)>
                                {{ $account->display_name ?: $account->phone_number }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="status">Estado</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">Todos</option>
                        @foreach ($statuses as $st)
                            <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>
                                {{ $st }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="category">Categoría</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">Todas</option>
                        @foreach (['MARKETING', 'UTILITY', 'AUTHENTICATION'] as $cat)
                            <option value="{{ $cat }}" @selected(($filters['category'] ?? '') === $cat)>
                                {{ $cat }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filtrar</button>
                    <a href="{{ route('whatsapp.templates.index') }}" class="btn btn-outline-secondary">Limpiar</a>
                </div>
            </div>
        </div>
    </form>
@endsection