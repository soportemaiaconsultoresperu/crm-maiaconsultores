{{--
    SweetAlert confirmation. Renders a small <form data-swal-confirm> with a
    submit button. When clicked, opens a SweetAlert dialog asking for
    confirmation. If the user confirms, the form is submitted.

    Supports an optional input (text or textarea) so the form can collect a
    reason before submitting — used by deactivate flows.

    Replaces:
        - onsubmit="return confirm('…')" on forms
        - <x-modal id="…-deactivate-modal"> blocks for destructive actions

    Usage:
        <x-swal-confirm
            :action="route('customers.destroy', $customer)"
            method="DELETE"
            title="¿Desactivar cliente?"
            text="El cliente no se mostrará en listados, pero se conserva en la base."
            type="warning"
            input="textarea"
            input-name="reason"
            input-label="Motivo"
            input-required="true"
            button-class="btn-outline-danger">
            <i class="bi bi-slash-circle me-1"></i> Desactivar
        </x-swal-confirm>
--}}
@props([
    'action' => '',
    'method' => 'DELETE',
    'title' => '¿Confirmar acción?',
    'text' => 'Esta acción no se puede deshacer.',
    'type' => 'warning',
    'confirmText' => 'Sí, continuar',
    'cancelText' => 'Cancelar',
    'buttonClass' => 'btn-outline-danger',
    'formClass' => '',            // applied to the wrapper <form>; 'class' is a PHP reserved word so we use 'formClass'
    'input' => null,             // null | 'text' | 'textarea'
    'inputName' => null,         // field name to submit (e.g. 'reason')
    'inputLabel' => '',
    'inputRequired' => false,
    'inputPlaceholder' => '',
])

<form method="POST" action="{{ $action }}" class="d-inline {{ $formClass }}" {{ $attributes }}>
    @csrf
    @method($method)

    <button type="submit"
            class="btn {{ $buttonClass }}"
            data-swal-confirm
            data-swal-title="{{ $title }}"
            data-swal-text="{{ $text }}"
            data-swal-type="{{ $type }}"
            data-swal-confirm-text="{{ $confirmText }}"
            data-swal-cancel-text="{{ $cancelText }}"
            @if ($input)
                data-swal-input="{{ $input }}"
                data-swal-input-name="{{ $inputName }}"
                data-swal-input-label="{{ $inputLabel }}"
                data-swal-input-required="{{ $inputRequired ? 'true' : 'false' }}"
                data-swal-input-placeholder="{{ $inputPlaceholder }}"
            @endif>
        {{ $slot }}
    </button>
</form>