@extends('layouts.app')

@section('title', $kindLabel)
@section('page-title', $kindLabel)

@section('content')
    @if (session('catalog_highlight'))
        @php($highlightId = session('catalog_highlight'))
    @endif

    @if ($errors->any())
        <x-alert type="error">{{ $errors->first() }}</x-alert>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <x-table :title="$kindLabel">
                @slot('headers')
                    <tr>
                        <th>Nombre</th>
                        @if (in_array('code', $kindConfig['extras'] ?? [], true))
                            <th>Código</th>
                        @endif
                        @if (in_array('symbol', $kindConfig['extras'] ?? [], true))
                            <th>Símbolo</th>
                        @endif
                        @if (in_array('decimals', $kindConfig['extras'] ?? [], true))
                            <th>Decimales</th>
                        @endif
                        @if (in_array('rate', $kindConfig['extras'] ?? [], true))
                            <th>Tasa</th>
                        @endif
                        @if (in_array('stage_type', $kindConfig['extras'] ?? [], true))
                            <th>Tipo</th>
                        @endif
                        @if (in_array('default_probability', $kindConfig['extras'] ?? [], true))
                            <th>Probabilidad</th>
                        @endif
                        @if (in_array('is_final', $kindConfig['extras'] ?? [], true))
                            <th>Final</th>
                        @endif
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                @endslot

                @slot('rows')
                    @forelse ($rows as $row)
                        <tr @if (isset($highlightId) && (int) $row->getKey() === (int) $highlightId) class="table-warning" @endif>
                            <td>
                                <strong>{{ $row->name }}</strong>
                                <div class="small text-secondary">slug: {{ $row->slug ?? $row->code }}</div>
                            </td>
                            @if (in_array('code', $kindConfig['extras'] ?? [], true))
                                <td>{{ $row->code }}</td>
                            @endif
                            @if (in_array('symbol', $kindConfig['extras'] ?? [], true))
                                <td>{{ $row->symbol }}</td>
                            @endif
                            @if (in_array('decimals', $kindConfig['extras'] ?? [], true))
                                <td>{{ $row->decimals }}</td>
                            @endif
                            @if (in_array('rate', $kindConfig['extras'] ?? [], true))
                                <td>{{ number_format((float) $row->rate, 2) }}%</td>
                            @endif
                            @if (in_array('stage_type', $kindConfig['extras'] ?? [], true))
                                <td><span class="badge text-bg-info">{{ $row->stage_type }}</span></td>
                            @endif
                            @if (in_array('default_probability', $kindConfig['extras'] ?? [], true))
                                <td>{{ number_format((float) $row->default_probability, 0) }}%</td>
                            @endif
                            @if (in_array('is_final', $kindConfig['extras'] ?? [], true))
                                <td>
                                    @if ($row->is_final)
                                        <span class="badge text-bg-warning">Final</span>
                                    @else
                                        <span class="badge text-bg-secondary">No final</span>
                                    @endif
                                </td>
                            @endif
                            <td><x-badge-status :status="$row->is_active ? 'active' : 'inactive'"/></td>
                            <td class="text-end text-nowrap">
                                @if ($row->is_active)
                                    <button type="button" class="btn btn-sm btn-warning"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#edit-{{ $row->getKey() }}">
                                        <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                                    Editar</button>
                                    <form method="POST" action="{{ route('admin.catalogs.deactivate', ['kind' => $kind, 'row' => $row->getKey()]) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="reason" value="Desactivación desde administración">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('¿Desactivar este registro?')">
                                            <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
                                        Desactivar</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.catalogs.activate', ['kind' => $kind, 'row' => $row->getKey()]) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                                        Activar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        @if ($row->is_active)
                            <tr class="collapse" id="edit-{{ $row->getKey() }}">
                                <td colspan="10">
                                    <form method="POST" action="{{ route('admin.catalogs.update', ['kind' => $kind, 'row' => $row->getKey()]) }}" class="row g-2 align-items-end">
                                        @csrf
                                        <div class="col-auto">
                                            <label class="form-label small">Nombre</label>
                                            <input type="text" name="name" value="{{ $row->name }}" class="form-control form-control-sm" required>
                                        </div>
                                        @if (in_array('code', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto">
                                                <label class="form-label small">Código</label>
                                                <input type="text" name="code" value="{{ $row->code }}" class="form-control form-control-sm" maxlength="3" required>
                                            </div>
                                        @else
                                            <div class="col-auto">
                                                <label class="form-label small">Slug</label>
                                                <input type="text" name="slug" value="{{ $row->slug ?? '' }}" class="form-control form-control-sm" maxlength="100">
                                            </div>
                                        @endif
                                        @if (in_array('symbol', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto">
                                                <label class="form-label small">Símbolo</label>
                                                <input type="text" name="symbol" value="{{ $row->symbol }}" class="form-control form-control-sm" maxlength="10" required>
                                            </div>
                                        @endif
                                        @if (in_array('decimals', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto">
                                                <label class="form-label small">Decimales</label>
                                                <input type="number" name="decimals" value="{{ $row->decimals }}" class="form-control form-control-sm" min="0" max="6">
                                            </div>
                                        @endif
                                        @if (in_array('rate', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto">
                                                <label class="form-label small">Tasa (%)</label>
                                                <input type="number" step="0.01" name="rate" value="{{ $row->rate }}" class="form-control form-control-sm" min="0" max="100" required>
                                            </div>
                                        @endif
                                        @if (in_array('stage_type', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto">
                                                <label class="form-label small">Tipo de etapa</label>
                                                <select name="stage_type" class="form-select form-select-sm">
                                                    @foreach (['open' => 'Abierta', 'won' => 'Ganada', 'lost' => 'Perdida'] as $key => $label)
                                                        <option value="{{ $key }}" @selected($row->stage_type === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                        @if (in_array('default_probability', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto">
                                                <label class="form-label small">Probabilidad (%)</label>
                                                <input type="number" step="0.01" name="default_probability" value="{{ $row->default_probability }}" class="form-control form-control-sm" min="0" max="100">
                                            </div>
                                        @endif
                                        @if (in_array('is_final', $kindConfig['extras'] ?? [], true))
                                            <div class="col-auto d-flex align-items-end">
                                                <div class="form-check">
                                                    <input type="hidden" name="is_final" value="0">
                                                    <input class="form-check-input" type="checkbox" name="is_final" id="is_final-{{ $row->getKey() }}" value="1" @checked($row->is_final)>
                                                    <label class="form-check-label small" for="is_final-{{ $row->getKey() }}">Final</label>
                                                </div>
                                            </div>
                                        @endif
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="10">
                                @include('layouts.partials.empty-state', [
                                    'message' => 'No hay registros en este catálogo.',
                                ])
                            </td>
                        </tr>
                    @endforelse
                @endslot
            </x-table>
        </div>

        <div class="col-lg-4">
            @can('catalogs.manage')
                <div class="card">
                    <div class="card-header"><h3 class="card-title mb-0">Nuevo registro</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.catalogs.store', ['kind' => $kind]) }}">
                            @csrf
                            <x-text-input name="name" label="Nombre" required/>
                            @if (in_array('code', $kindConfig['extras'] ?? [], true))
                                <x-text-input name="code" label="Código ISO 4217" help="3 letras mayúsculas" required/>
                                <x-text-input name="symbol" label="Símbolo" required/>
                                <x-text-input name="decimals" label="Decimales" type="number" :value="2" help="0 a 6"/>
                            @else
                                <x-text-input name="slug" label="Slug" help="Opcional. Minúsculas, números y guiones."/>
                            @endif
                            @if (in_array('rate', $kindConfig['extras'] ?? [], true))
                                <x-text-input name="rate" label="Tasa (%)" type="number" step="0.01" required/>
                            @endif
                            @if (in_array('stage_type', $kindConfig['extras'] ?? [], true))
                                <x-select
                                    name="stage_type"
                                    label="Tipo de etapa"
                                    :options="['open' => 'Abierta', 'won' => 'Ganada', 'lost' => 'Perdida']"
                                    :value="'open'"
                                    required
                                />
                                <x-text-input name="default_probability" label="Probabilidad (%)" type="number" step="0.01" :value="0"/>
                            @endif
                            @if (in_array('is_final', $kindConfig['extras'] ?? [], true))
                                <div class="form-check">
                                    <input type="hidden" name="is_final" value="0">
                                    <input class="form-check-input" type="checkbox" name="is_final" id="is_final_new" value="1">
                                    <label class="form-check-label" for="is_final_new">Estado final</label>
                                </div>
                            @endif
                            <button type="submit" class="btn btn-primary w-100 mt-3">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Crear
                            </button>
                        </form>
                    </div>
                </div>
            @endcan
        </div>
    </div>
@endsection