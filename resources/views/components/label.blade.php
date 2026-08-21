@props(['for' => null, 'label' => null, 'required' => false])

<label for="{{ $for }}" class="form-label mb-1">
    {{ $label ?? $slot }}
    @if ($required)
        <span class="text-danger" aria-hidden="true">*</span>
    @endif
</label>
