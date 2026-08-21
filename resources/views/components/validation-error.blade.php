@props(['name'])

@error($name)
    <div class="invalid-feedback d-block" role="alert">
        {{ $message }}
    </div>
@enderror
