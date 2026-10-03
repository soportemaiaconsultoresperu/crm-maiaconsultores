@php
    $prefix = 'new-field-'.($section?->id ?? 'none');
@endphp
<form method="POST" action="{{ route('content.fields.store', $project) }}" class="row g-3 border-top pt-3">
    @csrf
    <h3 class="h6">Crear campo{{ $section ? ' en '.$section->name : ' sin sección' }}</h3>
    <div class="col-md-3">
        <label for="label-{{ $prefix }}" class="form-label">Etiqueta</label>
        <input id="label-{{ $prefix }}" name="label" required maxlength="150" class="form-control" aria-describedby="label-help-{{ $prefix }}">
        <small id="label-help-{{ $prefix }}">La clave del campo se genera a partir de la etiqueta y queda fija.</small>
    </div>
    <div class="col-md-3">
        <label for="type-{{ $prefix }}" class="form-label">Tipo</label>
        <select id="type-{{ $prefix }}" name="type" class="form-select" required aria-describedby="type-help-{{ $prefix }}">
            @foreach ($fieldTypes as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
        <small id="type-help-{{ $prefix }}">El tipo queda fijo al crear el campo.</small>
    </div>
    <div class="col-md-3">
        <label for="section-{{ $prefix }}" class="form-label">Sección</label>
        <select id="section-{{ $prefix }}" name="content_field_section_id" class="form-select">
            <option value="">Sin sección</option>
            @foreach ($sections as $candidate)
                <option value="{{ $candidate->id }}" @selected($section && $candidate->id === $section->id)>{{ $candidate->name }}{{ $candidate->is_active ? '' : ' (Retirada)' }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label for="sort-{{ $prefix }}" class="form-label">Orden</label>
        <input id="sort-{{ $prefix }}" type="number" name="sort" value="0" min="0" max="2147483647" required class="form-control">
    </div>
    <div class="col-md-6">
        <label for="help-{{ $prefix }}" class="form-label">Texto de ayuda (opcional)</label>
        <input id="help-{{ $prefix }}" name="help_text" maxlength="2000" class="form-control">
    </div>
    <div class="col-md-3 align-self-end">
        <div class="form-check">
            <input type="hidden" name="required" value="0">
            <input class="form-check-input" type="checkbox" name="required" value="1" id="required-{{ $prefix }}">
            <label class="form-check-label" for="required-{{ $prefix }}">Obligatorio</label>
        </div>
    </div>
    <div class="col-12"><button type="submit" class="btn btn-primary">Crear campo</button></div>
</form>
