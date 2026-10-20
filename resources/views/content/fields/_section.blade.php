@php
    $prefix = 'section-'.$section->id;
@endphp
<section class="card mb-3" aria-labelledby="heading-{{ $prefix }}" data-field-section="{{ $section->id }}">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0" id="heading-{{ $prefix }}">{{ $section->name }}</h2>
        <span class="badge {{ $section->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $section->is_active ? 'Activa' : 'Inactiva' }}</span>
    </div>
    <div class="card-body">
        <p>Identificador fijo: <code>{{ $section->slug }}</code></p>

        <div class="d-flex flex-wrap gap-3 mb-3">
            <form method="POST" action="{{ route('content.fields.sections.update', [$project, $section->id]) }}">
                @csrf @method('PUT')
                <label for="name-{{ $prefix }}" class="form-label">Nombre</label>
                <input id="name-{{ $prefix }}" name="name" value="{{ $section->name }}" required maxlength="150" class="form-control mb-2">
                <button class="btn btn-outline-primary" type="submit">Guardar nombre</button>
            </form>

            <form method="POST" action="{{ route('content.fields.sections.reorder', [$project, $section->id]) }}">
                @csrf
                <label for="sort-{{ $prefix }}" class="form-label">Orden</label>
                <input id="sort-{{ $prefix }}" type="number" name="sort" value="{{ $section->sort }}" min="0" max="2147483647" required class="form-control mb-2">
                <button class="btn btn-outline-primary" type="submit">Guardar orden</button>
            </form>

            <form method="POST" action="{{ route('content.fields.sections.state', [$project, $section->id]) }}" class="align-self-end">
                @csrf
                <input type="hidden" name="is_active" value="{{ $section->is_active ? 0 : 1 }}">
                <button class="btn {{ $section->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}" type="submit">{{ $section->is_active ? 'Desactivar' : 'Reactivar' }}</button>
            </form>
        </div>

        @forelse ($sectionFields as $field)
            @include('content.fields._field', [
                'project' => $project,
                'field' => $field,
                'sections' => $sections,
            ])
        @empty
            <p class="text-muted">Esta sección todavía no tiene campos.</p>
        @endforelse

        @include('content.fields._create_field', [
            'project' => $project,
            'section' => $section,
            'sections' => $sections,
            'fieldTypes' => $fieldTypes,
        ])
    </div>
</section>
