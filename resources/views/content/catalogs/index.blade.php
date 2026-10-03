@extends('layouts.app')

@section('title', 'Catálogos de '.$project->name)
@section('page-title', 'Catálogos de '.$project->name)

@section('content')
    <a href="{{ route('content.projects.show', $project) }}" class="btn btn-outline-secondary mb-3">Volver al proyecto</a>
    <p>Las opciones retiradas conservan sus referencias históricas. El identificador no cambia al renombrar una opción.</p>
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @foreach ($groups as $key => $group)
        <section class="card mb-3" aria-labelledby="heading-{{ $key }}">
            <div class="card-header"><h2 class="card-title" id="heading-{{ $key }}">{{ $group['label'] }}</h2></div>
            <div class="card-body">
                @forelse ($group['options'] as $option)
                    @php
                        $parameters = [$project, $key, $option->id];
                    @endphp
                    <div class="border rounded p-3 mb-3" data-catalog-option="{{ $key }}-{{ $option->id }}">
                        <p><strong>{{ $option->name }}</strong> <code>{{ $option->slug }}</code>
                            <span class="badge {{ $option->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $option->is_active ? 'Activa' : 'Retirada' }}</span>
                            @if ($key === 'topics')
                                — Pilar: {{ $groups['pillars']['options']->firstWhere('id', $option->content_pillar_id)?->name }}
                            @endif
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('content.catalogs.update', $parameters) }}">
                                @csrf @method('PUT')
                                @if ($key === 'topics')<input type="hidden" name="content_pillar_id" value="{{ $option->content_pillar_id }}">@endif
                                <label for="name-{{ $key }}-{{ $option->id }}" class="form-label">Nombre</label>
                                <input id="name-{{ $key }}-{{ $option->id }}" name="name" value="{{ $option->name }}" required maxlength="150" class="form-control mb-2">
                                <button class="btn btn-outline-primary" type="submit">Guardar nombre</button>
                            </form>
                            <form method="POST" action="{{ route('content.catalogs.reorder', $parameters) }}">
                                @csrf
                                @if ($key === 'topics')<input type="hidden" name="content_pillar_id" value="{{ $option->content_pillar_id }}">@endif
                                <label for="sort-{{ $key }}-{{ $option->id }}" class="form-label">Orden</label>
                                <input id="sort-{{ $key }}-{{ $option->id }}" type="number" name="sort" value="{{ $option->sort }}" min="0" max="2147483647" required class="form-control mb-2">
                                <button class="btn btn-outline-primary" type="submit">Guardar orden</button>
                            </form>
                            <form method="POST" action="{{ route('content.catalogs.state', $parameters) }}" class="align-self-end">
                                @csrf
                                @if ($key === 'topics')<input type="hidden" name="content_pillar_id" value="{{ $option->content_pillar_id }}">@endif
                                <input type="hidden" name="is_active" value="{{ $option->is_active ? 0 : 1 }}">
                                <button class="btn {{ $option->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}" type="submit">{{ $option->is_active ? 'Desactivar' : 'Reactivar' }}</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-muted">No hay opciones registradas en este catálogo.</p>
                @endforelse
                <form method="POST" action="{{ route('content.catalogs.store', [$project, $key]) }}" class="row g-3">
                    @csrf
                    <h3 class="h6">Crear opción</h3>
                    @if ($key === 'topics')
                        <div class="col-md-3">
                            <label for="new-pillar" class="form-label">Pilar</label>
                            <select id="new-pillar" name="content_pillar_id" class="form-select" required>
                                <option value="">Seleccione un pilar</option>
                                @foreach ($groups['pillars']['options'] as $pillar)
                                    <option value="{{ $pillar->id }}">{{ $pillar->name }}{{ $pillar->is_active ? '' : ' (Retirado)' }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-md-3">
                        <label for="new-name-{{ $key }}" class="form-label">Nombre</label>
                        <input id="new-name-{{ $key }}" name="name" required maxlength="150" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label for="new-slug-{{ $key }}" class="form-label">Identificador</label>
                        <input id="new-slug-{{ $key }}" name="slug" required maxlength="150" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" aria-describedby="slug-help-{{ $key }}">
                        <small id="slug-help-{{ $key }}">Minúsculas, números y guiones. No se puede cambiar después.</small>
                    </div>
                    <div class="col-md-2">
                        <label for="new-sort-{{ $key }}" class="form-label">Orden</label>
                        <input id="new-sort-{{ $key }}" type="number" name="sort" value="0" min="0" max="2147483647" required class="form-control">
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-primary">Crear opción</button></div>
                </form>
            </div>
        </section>
    @endforeach
@endsection
