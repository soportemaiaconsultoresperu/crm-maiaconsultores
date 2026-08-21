@props([
    'name',
    'label' => null,
    'options' => [],
    'value' => null,
    'required' => false,
    'placeholder' => null,
    'help' => null,
])

@if ($label)
    <x-label :for="$name" :label="$label" :required="$required"/>
@endif

<select
    name="{{ $name }}"
    id="{{ $name }}"
    class="form-select @error($name) is-invalid @enderror"
    @if ($required) required @endif
    {{ $attributes }}
>
    @if ($placeholder)
        <option value="" {{ blank(old($name, $value)) ? 'selected' : '' }}>{{ $placeholder }}</option>
    @endif

    @foreach ($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}" {{ (string) old($name, $value) === (string) $optionValue ? 'selected' : '' }}>
            {{ $optionLabel }}
        </option>
    @endforeach

    {{ $slot }}
</select>

@if ($help)
    <div class="form-text">{{ $help }}</div>
@endif

<x-validation-error :name="$name"/>
