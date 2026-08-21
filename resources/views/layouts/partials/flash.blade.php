{{--
    Flash messages: success / error one-shot notifications.
    Controllers use ->with('status', ...) or ->with('error', ...).

    Rendered as SweetAlert2 toasts via <x-swal-toast>. The swal-helpers.js
    module picks these up on DOMContentLoaded and turns them into toasts.
--}}
<x-swal-toast type="success" :message="session('status')" />
<x-swal-toast type="error" :message="session('error')" />

{{-- Backwards compatibility: if any older Bootstrap alert is still rendered
    elsewhere, keep the visible alert for users who can't run JS. The toast
    is the primary feedback channel. --}}
@if (session('status'))
    <noscript>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>{{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    </noscript>
@endif

@if (session('error'))
    <noscript>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    </noscript>
@endif