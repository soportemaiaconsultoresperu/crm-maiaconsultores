{{--
    Bootstrap 5 modal (no jQuery — ADR-010). Toggle via data-bs-* attrs.
    Slots: trigger (optional button), content (body), footer.
--}}
@props(['id', 'title' => null, 'size' => null])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-label" aria-hidden="true">
    <div class="modal-dialog {{ $size ? 'modal-'.$size : '' }}">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}-label">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                {{ $slot }}
            </div>
            @isset($footer)
                <div class="modal-footer">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
