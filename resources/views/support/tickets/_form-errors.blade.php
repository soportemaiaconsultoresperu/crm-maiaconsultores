{{-- Validation errors of one ticket-page action form ($bag = the form's error bag, see SupportTicketController). --}}
@php($bagErrors = $errors->getBag($bag))
@if ($bagErrors->any())
    <div class="invalid-feedback d-block" role="alert" data-form-errors="{{ $bag }}">
        @foreach ($bagErrors->all() as $message)
            <div>{{ $message }}</div>
        @endforeach
    </div>
@endif
