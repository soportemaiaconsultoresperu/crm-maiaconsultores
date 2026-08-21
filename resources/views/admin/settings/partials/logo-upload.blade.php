{{--
    Logo upload widget — rendered inside the Settings form for the
    `company.logo_path` setting. The form posts to its own dedicated route
    (POST admin.settings.logo.upload) because multipart uploads must not
    collide with the bulk settings PUT.

    Props:
      - $value       : current path stored in the setting (may be empty).
      - $label       : translated label for the setting.
      - $help        : translated help text for the setting.
      - $previewUrl  : signed/temporary URL to fetch the current logo, or null.
--}}

<div class="mb-2">
    <label class="form-label">{{ $label }}</label>

    @if (!empty($value) && $previewUrl)
        <div class="mb-2 d-flex align-items-center gap-3">
            <img src="{{ $previewUrl }}"
                 alt="Logo actual"
                 class="img-thumbnail"
                 style="max-height: 96px; max-width: 240px; object-fit: contain;">
            <div>
                <form method="POST"
                      action="{{ route('admin.settings.logo.remove') }}"
                      class="d-inline"
                      onsubmit="return confirm('¿Quitar el logo actual?')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash me-1" aria-hidden="true"></i> Quitar logo
                    </button>
                </form>
            </div>
        </div>
    @else
        <p class="text-secondary small mb-2">No hay logo cargado todavía.</p>
    @endif

    <form method="POST"
          action="{{ route('admin.settings.logo.upload') }}"
          enctype="multipart/form-data"
          class="d-flex align-items-center gap-2">
        @csrf
        <input type="file"
               name="logo"
               accept="image/png,image/jpeg,image/webp"
               class="form-control @error('logo') is-invalid @enderror"
               required>
        <button type="submit" class="btn btn-outline-primary text-nowrap">
            <i class="bi bi-upload me-1" aria-hidden="true"></i> Subir logo
        </button>
        @error('logo')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </form>

    @if ($help)
        <div class="form-text">{{ $help }}</div>
    @endif
</div>
