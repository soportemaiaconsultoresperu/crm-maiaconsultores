{{--
    One project-defined custom field, rendered by its type (T6c).
    Expected: $field (ContentFieldDefinition with options), $values (keyed array).
--}}
@php
    $key = $field->key;
    $id = 'field-'.$key;
    $errorKey = 'fields.'.$key;
    $value = old($errorKey, $values[$key] ?? null);
    $invalid = $errors->has($errorKey) ? ' is-invalid' : '';
@endphp

<div class="col-md-6" data-custom-field="{{ $key }}">
    <x-label :for="$id" :label="$field->label" :required="$field->required"/>
    @if ($field->help_text)
        <div class="form-text mb-1">{{ $field->help_text }}</div>
    @endif

    @switch($field->type)
        @case(\App\Enums\Content\ContentFieldType::Text)
            <input type="text" name="fields[{{ $key }}]" id="{{ $id }}" value="{{ $value }}"
                   class="form-control{{ $invalid }}" @if ($field->required) required @endif>
            @break

        @case(\App\Enums\Content\ContentFieldType::LongText)
            <textarea name="fields[{{ $key }}]" id="{{ $id }}" rows="3"
                      class="form-control{{ $invalid }}" @if ($field->required) required @endif>{{ $value }}</textarea>
            @break

        @case(\App\Enums\Content\ContentFieldType::Date)
            <input type="date" name="fields[{{ $key }}]" id="{{ $id }}" value="{{ $value }}"
                   class="form-control{{ $invalid }}" @if ($field->required) required @endif>
            @break

        @case(\App\Enums\Content\ContentFieldType::Number)
            <input type="number" step="0.01" name="fields[{{ $key }}]" id="{{ $id }}" value="{{ $value }}"
                   class="form-control{{ $invalid }}" @if ($field->required) required @endif>
            @break

        @case(\App\Enums\Content\ContentFieldType::SingleSelection)
            <select name="fields[{{ $key }}]" id="{{ $id }}" class="form-select{{ $invalid }}" @if ($field->required) required @endif>
                <option value="">Seleccione una opción</option>
                @foreach ($field->options as $option)
                    <option value="{{ $option->id }}" @selected((string) $value === (string) $option->id)>
                        {{ $option->label }}{{ $option->is_active ? '' : ' (Retirada)' }}
                    </option>
                @endforeach
            </select>
            @break

        @case(\App\Enums\Content\ContentFieldType::MultipleSelection)
            <select name="fields[{{ $key }}][]" id="{{ $id }}" multiple class="form-select{{ $invalid }}" @if ($field->required) required @endif>
                @foreach ($field->options as $option)
                    <option value="{{ $option->id }}" @selected(in_array($option->id, (array) $value, true))>
                        {{ $option->label }}{{ $option->is_active ? '' : ' (Retirada)' }}
                    </option>
                @endforeach
            </select>
            <div class="form-text">Puede seleccionar varias opciones.</div>
            @break
    @endswitch

    <x-validation-error :name="$errorKey"/>
</div>
