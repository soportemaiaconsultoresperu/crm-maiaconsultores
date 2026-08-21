{{--
    SweetAlert toast trigger. Renders an invisible DOM node that the
    swal-helpers.js module picks up on DOMContentLoaded and turns into a
    real toast. Use this instead of <x-alert> for ephemeral success/error
    notifications — toasts don't take vertical space and feel snappier.

    Usage:
        <x-swal-toast type="success" :message="session('status')" />
        <x-swal-toast type="error" :message="$error" />
--}}
@props([
    'type' => 'info',
    'message' => null,
    'timer' => 3500,
])

@if (! empty($message))
    <div data-swal-toast
         data-swal-type="{{ $type }}"
         data-swal-message="{{ $message }}"
         data-swal-timer="{{ $timer }}"
         aria-hidden="true"></div>
@endif