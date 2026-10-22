@php
    $prefix = 'field-'.$field->id;
@endphp
<div class="border rounded p-3 mb-3" data-field="{{ $field->id }}">
    <p class="mb-2">
        <strong>{{ $field->label }}</strong>
        <span class="badge {{ $field->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $field->is_active ? 'Activo' : 'Inactivo' }}</span>
        @if ($field->required)<span class="badge text-bg-info">Obligatorio</span>@endif
    </p>

    <p class="mb-2">
        Clave fija: <code>{{ $field->key }}</code>
        · Tipo fijo: <code>{{ $field->type->label() }}</code>
        <br><small class="text-muted">La clave y el tipo no se pueden cambiar: los valores guardados dependen de ellos.</small>
    </p>

    @if ($field->help_text)
        <p class="text-muted">{{ $field->help_text }}</p>
    @endif

    <div class="d-flex flex-wrap gap-3 mb-3">
        <form method="POST" action="{{ route('content.fields.update', [$project, $field->id]) }}">
            @csrf @method('PUT')
            <label for="label-{{ $prefix }}" class="form-label">Etiqueta</label>
            <input id="label-{{ $prefix }}" name="label" value="{{ $field->label }}" required maxlength="150" class="form-control mb-2">

            <label for="section-{{ $prefix }}" class="form-label">Sección</label>
            <select id="section-{{ $prefix }}" name="content_field_section_id" class="form-select mb-2">
                <option value="">Sin sección</option>
                @foreach ($sections as $candidate)
                    <option value="{{ $candidate->id }}" @selected($field->content_field_section_id === $candidate->id)>{{ $candidate->name }}{{ $candidate->is_active ? '' : ' (Inactiva)' }}</option>
                @endforeach
            </select>

            <label for="help-{{ $prefix }}" class="form-label">Texto de ayuda (opcional)</label>
            <input id="help-{{ $prefix }}" name="help_text" value="{{ $field->help_text }}" maxlength="2000" class="form-control mb-2">

            <div class="form-check mb-2">
                <input type="hidden" name="required" value="0">
                <input class="form-check-input" type="checkbox" name="required" value="1" id="required-{{ $prefix }}" @checked($field->required)>
                <label class="form-check-label" for="required-{{ $prefix }}">Obligatorio</label>
            </div>

            <button class="btn btn-outline-primary" type="submit">Guardar campo</button>
        </form>

        <form method="POST" action="{{ route('content.fields.reorder', [$project, $field->id]) }}">
            @csrf
            <label for="sort-{{ $prefix }}" class="form-label">Orden</label>
            <input id="sort-{{ $prefix }}" type="number" name="sort" value="{{ $field->sort }}" min="0" max="2147483647" required class="form-control mb-2">
            <button class="btn btn-outline-primary" type="submit">Guardar orden</button>
        </form>

        <form method="POST" action="{{ route('content.fields.state', [$project, $field->id]) }}" class="align-self-end">
            @csrf
            <input type="hidden" name="is_active" value="{{ $field->is_active ? 0 : 1 }}">
            <button class="btn {{ $field->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}" type="submit">{{ $field->is_active ? 'Desactivar' : 'Reactivar' }}</button>
        </form>
    </div>

    @if ($field->acceptsOptions())
        <h3 class="h6">Opciones de la lista</h3>
        @forelse ($field->options as $option)
            @php
                $optionPrefix = 'option-'.$option->id;
            @endphp
            <div class="border rounded p-2 mb-2" data-field-option="{{ $option->id }}">
                <p class="mb-2">
                    <strong>{{ $option->label }}</strong> <code>{{ $option->slug }}</code>
                    <span class="badge {{ $option->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $option->is_active ? 'Activa' : 'Inactiva' }}</span>
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('content.fields.options.update', [$project, $field->id, $option->id]) }}">
                        @csrf @method('PUT')
                        <label for="label-{{ $optionPrefix }}" class="form-label">Etiqueta</label>
                        <input id="label-{{ $optionPrefix }}" name="label" value="{{ $option->label }}" required maxlength="150" class="form-control mb-2">
                        <button class="btn btn-outline-primary" type="submit">Guardar etiqueta</button>
                    </form>

                    <form method="POST" action="{{ route('content.fields.options.reorder', [$project, $field->id, $option->id]) }}">
                        @csrf
                        <label for="sort-{{ $optionPrefix }}" class="form-label">Orden</label>
                        <input id="sort-{{ $optionPrefix }}" type="number" name="sort" value="{{ $option->sort }}" min="0" max="2147483647" required class="form-control mb-2">
                        <button class="btn btn-outline-primary" type="submit">Guardar orden</button>
                    </form>

                    <form method="POST" action="{{ route('content.fields.options.state', [$project, $field->id, $option->id]) }}" class="align-self-end">
                        @csrf
                        <input type="hidden" name="is_active" value="{{ $option->is_active ? 0 : 1 }}">
                        <button class="btn {{ $option->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}" type="submit">{{ $option->is_active ? 'Desactivar' : 'Reactivar' }}</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-muted">Esta lista todavía no tiene opciones.</p>
        @endforelse

        <form method="POST" action="{{ route('content.fields.options.store', [$project, $field->id]) }}" class="row g-3 border-top pt-3">
            @csrf
            <h4 class="h6">Nueva opción</h4>
            <div class="col-md-4">
                <label for="new-label-{{ $prefix }}" class="form-label">Etiqueta</label>
                <input id="new-label-{{ $prefix }}" name="label" required maxlength="150" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="new-slug-{{ $prefix }}" class="form-label">Identificador</label>
                <input id="new-slug-{{ $prefix }}" name="slug" required maxlength="150" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" aria-describedby="new-slug-help-{{ $prefix }}">
                <small id="new-slug-help-{{ $prefix }}">Minúsculas, números y guiones. No se puede cambiar después.</small>
            </div>
            <div class="col-md-2">
                <label for="new-sort-{{ $prefix }}" class="form-label">Orden</label>
                <input id="new-sort-{{ $prefix }}" type="number" name="sort" value="0" min="0" max="2147483647" required class="form-control">
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary">Crear opción</button></div>
        </form>
    @else
        <p class="text-muted mb-0">Los campos de tipo {{ $field->type->label() }} no admiten opciones.</p>
    @endif
</div>
