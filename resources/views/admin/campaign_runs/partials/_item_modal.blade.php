@php
    /** @var \App\Models\CampaignActionItem $item */
    /** @var \App\Models\CampaignParticipant $participant */
    /** @var \App\Models\CampaignStep $step */
@endphp

<div class="modal fade" id="item-modal-{{ $item->id }}" tabindex="-1" aria-labelledby="item-modal-title-{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="item-modal-title-{{ $item->id }}">
                    {{ $step->title }}
                    <small class="text-secondary d-block fw-normal">{{ $participant->display_name }}</small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form id="item-form-{{ $item->id }}" method="POST" data-swal-loading
                  action="{{ route('admin.campaign_items.mark-realized', $item) }}"
                  data-default-action="{{ route('admin.campaign_items.mark-realized', $item) }}">
                @csrf
                <input type="hidden" name="action" value="mark_realized">

                <div class="modal-body">
                    <p class="text-secondary small mb-3">
                        Programado: {{ optional($item->scheduled_at)->format('d/m/Y H:i') ?? '—' }}
                        · Estado actual: <strong>{{ $item->status }}</strong>
                    </p>

                    <div class="mb-3">
                        <label class="form-label">Resultado</label>
                        <textarea name="result" class="form-control" rows="2"
                                  placeholder="Qué pasó al ejecutar esta acción">{{ old('result') }}</textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Respuesta del contacto</label>
                        <textarea name="contact_response" class="form-control" rows="2">{{ old('contact_response') }}</textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Observaciones internas</label>
                        <textarea name="observations" class="form-control" rows="2">{{ old('observations') }}</textarea>
                    </div>

                    <div class="mb-3 cancellation-field">
                        <label class="form-label">Motivo de cancelación <span class="text-danger">*</span></label>
                        <textarea name="cancellation_reason" class="form-control" rows="2"
                                  placeholder="Obligatorio solo si elegís 'Cancelar'">{{ old('cancellation_reason') }}</textarea>
                    </div>

                    <div class="mb-3 not-applicable-field">
                        <label class="form-label">Motivo de "No aplica" <span class="text-danger">*</span></label>
                        <textarea name="not_applicable_reason" class="form-control" rows="2"
                                  placeholder="Obligatorio solo si elegís 'No aplica'">{{ old('not_applicable_reason') }}</textarea>
                    </div>
                </div>

                <div class="modal-footer justify-content-between">
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-success btn-sm item-action-btn"
                                data-action="start"
                                data-target="{{ route('admin.campaign_items.start', $item) }}">
                            <i class="bi bi-play"></i> Iniciar
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm item-action-btn"
                                data-action="not_applicable"
                                data-target="{{ route('admin.campaign_items.mark-not-applicable', $item) }}">
                            No aplica
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm item-action-btn"
                                data-action="cancel"
                                data-target="{{ route('admin.campaign_items.cancel', $item) }}">
                            Cancelar
                        </button>
                    </div>
                    <button type="submit" class="btn btn-success item-submit-btn">
                        <i class="bi bi-check2"></i> Marcar realizado
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            // Un único listener delegado: aplica a TODOS los .item-action-btn de la página,
            // sin importar cuántos modales haya (uno por item).
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.item-action-btn');
                if (! btn) return;
                e.preventDefault();
                const form = btn.closest('form');
                const action = btn.dataset.action;
                form.querySelector('input[name="action"]').value = action;
                form.action = btn.dataset.target;
                // Reflejar visualmente qué acción eligió el usuario
                form.querySelectorAll('.item-action-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                form.submit();
            });
        </script>
    @endpush
@endonce