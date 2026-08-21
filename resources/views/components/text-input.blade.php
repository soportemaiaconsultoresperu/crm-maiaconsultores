@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'autocomplete' => null,
    'placeholder' => null,
    'help' => null,
])

@if ($label)
    <x-label :for="$name" :label="$label" :required="$required"/>
@endif

<input
    type="{{ $type }}"
    name="{{ $name }}"
    id="{{ $name }}"
    value="{{ old($name, $value) }}"
    class="form-control @error($name) is-invalid @enderror"
    @if ($required) required @endif
    @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
    @if ($placeholder) placeholder="{{ $placeholder }}" @endif
    {{ $attributes }}
>

@if ($help)
    <div class="form-text">{{ $help }}</div>
@endif

<x-validation-error :name="$name"/>
