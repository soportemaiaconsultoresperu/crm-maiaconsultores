@extends('layouts.app')

@section('title', 'Nueva ejecución de campaña')
@section('page-title', 'Nueva ejecución de campaña')

@section('content')
        @if (! $template)
            <x-alert type="warning">
                Selecciona una plantilla activa antes de crear una ejecución.
                <a href="{{ route('admin.campaign_templates.index') }}" class="alert-link">Ver plantillas</a>.
            </x-alert>
        @else
            @if ($errors->any())
                <x-alert type="danger">
                    <strong>No se pudo crear la ejecución:</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif
        <form method="POST" action="{{ route('admin.campaign_runs.store') }}" id="campaign-run-form">
            @csrf
            <input type="hidden" name="template_id" value="{{ $template->id }}">

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">Plantilla: {{ $template->name }}</h3></div>
                <div class="card-body">
                    <p class="text-secondary">{{ $template->description ?? 'Sin descripción.' }}</p>
                    <p class="small mb-0">
                        <strong>{{ $template->steps->count() }}</strong> pasos definidos · objetivo: <em>{{ $template->objective }}</em>
                    </p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">Datos de la ejecución</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <x-text-input name="name" label="Nombre" :required="true" :value="old('name', $template->name . ' — ' . now()->format('d/m/Y'))"/>
                        </div>
                        <div class="col-md-3">
                            <x-text-input name="starts_at" type="datetime-local" label="Fecha y hora de inicio" :required="true" :value="old('starts_at', now()->addDay()->format('Y-m-d\TH:i'))"/>
                        </div>
                        <div class="col-md-3">
                            <x-text-input name="ends_at_estimated" type="datetime-local" label="Fin estimado" :value="old('ends_at_estimated')"/>
                        </div>
                        <div class="col-md-12">
                            <x-label for="observations" label="Observaciones"/>
                            <textarea name="observations" id="observations" rows="2" class="form-control">{{ old('observations') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Selección de contactos</h3>
                    <span class="badge text-bg-info" id="selection-count">0 seleccionados</span>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="type" class="form-select form-select-sm" id="contact-type">
                                <option value="">Todos los tipos</option>
                                <option value="lead">Solo prospectos</option>
                                <option value="customer">Solo clientes</option>
                                <option value="contact">Solo contactos</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar nombre / documento / correo..." id="contact-q">
                        </div>
                        <div class="col-md-2">
<button type="button" class="btn btn-sm btn-outline-primary w-100"
                                        id="search-contacts-btn"
                                        data-search-endpoint="{{ route('admin.campaign_runs.search-contacts') }}">
                                    <i class="bi bi-search"></i> Buscar
                                </button>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="select-all-btn">
                                Seleccionar todos los filtrados
                            </button>
                        </div>
                    </div>
                    <div id="search-results" class="border rounded p-2 mb-2" style="max-height: 300px; overflow: auto;">
                        <p class="text-secondary text-center mb-0 py-3">Clic "Buscar" para ver resultados.</p>
                    </div>
                    <div id="selected-list" class="border rounded p-2" style="min-height: 60px;">
                        <p class="text-secondary text-center mb-0 py-2">Aún no hay contactos seleccionados.</p>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> Crear ejecución
                </button>
                <a href="{{ route('admin.campaign_runs.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    @endif

    @once
        @push('scripts')
<script>
                const searchEndpoint = document.getElementById('search-contacts-btn').dataset.searchEndpoint;
                const selected = new Map();
                const resultsEl = document.getElementById('search-results');
                const listEl = document.getElementById('selected-list');
                const countEl = document.getElementById('selection-count');

function renderResults(items) {
                    resultsEl.innerHTML = items.length === 0
                        ? '<p class="text-secondary text-center mb-0 py-3">Sin resultados.</p>'
                        : items.map(i => {
                            const checked = selected.has(`${i.subject_type}:${i.subject_id}`) ? 'checked' : '';
                            const sub = i.company_name && i.company_name !== i.display_name
                                ? `<small class="text-secondary d-block">${i.company_name} · ${i.subject_type}${i.doc_number ? ' · ' + i.doc_number : ''}</small>`
                                : `<small class="text-secondary d-block">${i.subject_type}${i.doc_number ? ' · ' + i.doc_number : ''}</small>`;
                            return `<label class="d-flex align-items-center gap-2 p-1 border-bottom">
                                <input type="checkbox" class="form-check-input search-result" value="${i.subject_type}:${i.subject_id}" data-name="${i.display_name}" ${checked}>
                                <span>
                                <strong>${i.display_name}</strong>
                                ${sub}
                                </span>
                            </label>`;
                        }).join('');
                }

function renderSelected() {
                    countEl.textContent = `${selected.size} seleccionados`;
                    if (selected.size === 0) {
                        listEl.innerHTML = '<p class="text-secondary text-center mb-0 py-2">Aún no hay contactos seleccionados.</p>';
                        return;
                    }
                    listEl.innerHTML = Array.from(selected.entries()).map(([key, name]) => {
                        const [subjectType, subjectId] = key.split(':');
                        return `<span class="badge text-bg-primary me-1 mb-1">
                            ${name}
                            <button type="button" class="btn-close btn-close-white btn-sm" data-key="${key}" aria-label="Quitar"></button>
                            <input type="hidden" name="participants[${subjectType}:${subjectId}][subject_type]" value="${subjectType}">
                            <input type="hidden" name="participants[${subjectType}:${subjectId}][subject_id]" value="${subjectId}">
                        </span>`;
                    }).join('');
                }

document.getElementById('search-contacts-btn').addEventListener('click', async () => {
                        const params = new URLSearchParams();
                        const q = document.getElementById('contact-q').value;
                        const type = document.getElementById('contact-type').value;
                        if (q) params.set('search', q);
                        if (type) params.set('type', type);
                        const resp = await fetch(`${searchEndpoint}?${params}`);
                        if (! resp.ok) {
                            resultsEl.innerHTML = `<p class="text-danger text-center mb-0 py-3">Error ${resp.status} al buscar contactos.</p>`;
                            return;
                        }
                        const data = await resp.json();
                        renderResults(data.results || []);
                    });

                document.getElementById('select-all-btn').addEventListener('click', () => {
                    document.querySelectorAll('.search-result').forEach(cb => {
                        if (! cb.checked) {
                            cb.checked = true;
                            cb.dispatchEvent(new Event('change'));
                        }
                    });
                });

                resultsEl.addEventListener('change', (e) => {
                    if (e.target.classList.contains('search-result')) {
                        const key = e.target.value;
                        if (e.target.checked) {
                            selected.set(key, e.target.dataset.name);
                        } else {
                            selected.delete(key);
                        }
                        renderSelected();
                    }
                });

listEl.addEventListener('click', (e) => {
                    if (e.target.classList.contains('btn-close')) {
                        const key = e.target.dataset.key;
                        selected.delete(key);
                        renderSelected();
                    }
                });

// Client-side guard: impedir submit si no hay participantes seleccionados.
                document.getElementById('campaign-run-form').addEventListener('submit', (e) => {
                    if (selected.size === 0) {
                        e.preventDefault();
                        listEl.scrollIntoView({behavior: 'smooth', block: 'center'});
                        listEl.innerHTML = '<p class="text-danger text-center mb-0 py-2">Seleccioná al menos un contacto antes de crear la ejecución.</p>';
                    }
                });
            </script>
        @endpush
    @endonce
@endsection
