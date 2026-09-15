@extends('layouts.app')

@section('title', 'Documentos académicos del dictado')
@section('page-title', 'Documentos académicos del dictado')

@section('content')
    @php
        // Presentation-only maps. Eligibility, the expected document type and
        // the document/delivery statuses are decided by CourseEligibilityService
        // and the Courses enums; this surface only renders their values in
        // Spanish and never decides which condition or document applies. The
        // enums expose no label(), so the wording and the badge colors live here,
        // exactly as the attendance and grade matrices do for their statuses.
        $missingMeta = [
            'payment' => 'Pago pendiente de completar',
            'participant_data' => 'Datos del participante incompletos',
            'edition_validations' => 'Validaciones del dictado pendientes',
            'academic_result' => 'Resultado académico o participación pendiente',
            'participation' => 'Participación de la charla sin confirmar',
            'enrollment_state' => 'La matrícula está retirada o el participante no asistió',
        ];
        $documentTypeMeta = [
            'approval_certificate' => 'Certificado de aprobación',
            'participation_constancy' => 'Constancia de participación',
            'talk_certificate' => 'Certificado de charla',
        ];
        $documentStatusMeta = [
            'pending_generation' => ['En generación', 'text-bg-secondary'],
            'current' => ['Vigente', 'text-bg-success'],
            'annulled' => ['Anulado', 'text-bg-danger'],
            'replaced' => ['Reemplazado', 'text-bg-warning'],
            'failed' => ['Fallido', 'text-bg-dark'],
        ];
        $deliveryStatusMeta = [
            'pending' => ['Entrega pendiente', 'text-bg-secondary'],
            'sent' => ['Enviado', 'text-bg-success'],
            'failed' => ['Entrega fallida', 'text-bg-danger'],
            'discarded' => ['Descartado', 'text-bg-light'],
        ];
        $fallbackMeta = static fn (string $value): array => [str_replace('_', ' ', $value), 'text-bg-secondary'];

        // Each control is revealed only to holders of the ability its own route
        // requires, so no rendered control can answer 403. Regeneration is
        // additionally gated by the `revoke` ability, because
        // CourseDocumentGenerationService::regenerate() authorizes it too.
        $canGenerate = Gate::allows('generate', App\Models\Courses\CourseAcademicDocument::class);
        $canAnnul = Gate::allows('revoke', App\Models\Courses\CourseAcademicDocument::class);
        $canRegenerate = $canGenerate && $canAnnul;

        // Same rule for the delivery actions: `course-talks.documents.send` is the
        // only ability their routes, their form contracts and the domain service
        // ask for, so the delivery controls are gated on exactly that ability.
        // They are additionally gated on the service's per-document deliverability
        // verdict ($deliverability): a document the domain would refuse gets no
        // control at all.
        $canSend = Gate::allows('send', App\Models\Courses\CourseAcademicDocument::class);

        // Attempt-level presentation maps. The append-only outbound-delivery
        // ledger is the only source of delivery truth; it decides the channel,
        // the status, the attempt count, the recipient reference and the error,
        // and this surface only renders those values in Spanish.
        $channelMeta = [
            'mail' => ['Correo', 'text-bg-info'],
            'whatsapp' => ['WhatsApp', 'text-bg-success'],
        ];
        $attemptStatusMeta = [
            'queued' => ['En cola', 'text-bg-secondary'],
            'sending' => ['Enviando', 'text-bg-info'],
            'sent' => ['Enviado', 'text-bg-success'],
            'delivered' => ['Entregado', 'text-bg-success'],
            'failed' => ['Intento fallido', 'text-bg-danger'],
            'skipped' => ['Omitido', 'text-bg-light'],
        ];

        // A handoff stays pending until the user confirms it: the confirmation
        // control is offered only while an opened WhatsApp handoff is unresolved.
        $pendingHandoffOf = static fn ($documentDeliveries) => $documentDeliveries->first(
            static fn ($delivery): bool => $delivery->channel === 'whatsapp'
                && in_array($delivery->status, ['queued', 'sending'], true),
        );
    @endphp

    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary mb-3">Volver al dictado</a>

    <div class="card mb-3" data-testid="course-talks-documents-edition">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Curso o charla</dt>
                <dd class="col-sm-9">{{ $edition->activity->name }} · {{ $edition->activity->type->label() }}</dd>
                <dt class="col-sm-3">Dictado</dt>
                <dd class="col-sm-9"><code>{{ $edition->code ?: '—' }}</code> · {{ $edition->modality->label() }}</dd>
                <dt class="col-sm-3">Participantes</dt>
                <dd class="col-sm-9">{{ $enrollments->count() }}</dd>
            </dl>
        </div>
    </div>

    {{-- A rejected action is reported under the `documents` key and the payload
         errors (an empty reason) under `reason`. Listing every message keeps both
         visible. --}}
    @if ($errors->any())
        <x-alert type="error" data-testid="course-talks-documents-errors">
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <x-table title="Documentos académicos por participante">
        @slot('headers')
            <tr>
                <th scope="col">Participante</th>
                <th scope="col">Documento esperado</th>
                <th scope="col">Elegibilidad</th>
                <th scope="col">Documentos registrados y acciones</th>
            </tr>
        @endslot
        @slot('rows')
            @forelse ($enrollments as $enrollment)
                @php
                    $result = $eligibility[$enrollment->id];
                    $current = $enrollment->academicDocuments->first(
                        static fn ($document) => $document->status === App\Enums\Courses\AcademicDocumentStatus::Current,
                    );
                @endphp
                <tr data-testid="course-talks-documents-row-{{ $enrollment->id }}">
                    <td>
                        <strong>{{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</strong>
                        @if ($document = $enrollment->participant->displayDocument())
                            <div class="text-secondary small">{{ $document }}</div>
                        @endif
                    </td>
                    <td data-testid="course-talks-documents-expected-{{ $enrollment->id }}">
                        @if ($result->documentType)
                            {{ $documentTypeMeta[$result->documentType->value] ?? $result->documentType->value }}
                        @else
                            <span class="text-secondary">Sin documento aplicable todavía</span>
                        @endif
                    </td>
                    <td data-testid="course-talks-documents-eligibility-{{ $enrollment->id }}">
                        @if ($result->eligible)
                            <span class="badge text-bg-success">Elegible</span>
                        @else
                            <span class="badge text-bg-warning">No elegible</span>
                            <p class="small mb-1 mt-2">Condiciones pendientes:</p>
                            <ul class="small mb-0">
                                @foreach ($result->missingConditions as $condition)
                                    <li>{{ $missingMeta[$condition] ?? str_replace('_', ' ', $condition) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                    <td data-testid="course-talks-documents-of-{{ $enrollment->id }}">
                        @forelse ($enrollment->academicDocuments as $document)
                            @php
                                $statusMeta = $documentStatusMeta[$document->status->value] ?? $fallbackMeta($document->status->value);
                                $deliveryMeta = $deliveryStatusMeta[$document->delivery_status->value] ?? $fallbackMeta($document->delivery_status->value);
                            @endphp
                            <div class="border rounded p-2 mb-2" data-testid="course-talks-document-{{ $document->id }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-1">
                                    <span class="fw-semibold">{{ $documentTypeMeta[$document->type->value] ?? $document->type->value }}</span>
                                    <span class="badge {{ $statusMeta[1] }}">{{ $statusMeta[0] }}</span>
                                </div>
                                <div class="small text-secondary">
                                    Código <code>{{ $document->code }}</code> · Emitido el {{ $document->issue_date?->format('d/m/Y') ?? '—' }}
                                </div>
                                <div class="small">
                                    <span class="badge {{ $deliveryMeta[1] }}">{{ $deliveryMeta[0] }}</span>
                                    <span class="text-secondary">Último envío: {{ $document->last_sent_at?->format('d/m/Y H:i') ?? 'Sin envíos' }}</span>
                                </div>
                                @if ($document->annul_reason)
                                    <div class="small text-secondary">Motivo registrado: {{ $document->annul_reason }}</div>
                                @endif

                                @if ($current !== null && $current->id === $document->id)
                                    @if ($canRegenerate)
                                        {{-- Plain HTML form on purpose: interpolation
                                             inside a Blade component's attributes is
                                             not evaluated, and these two payloads
                                             need their own form so each one keeps its
                                             own request validation. --}}
                                        <form method="POST" action="{{ route('course-talks.documents.regenerate', $document) }}" class="d-flex flex-wrap gap-1 mt-2" data-testid="course-talks-document-regenerate-form-{{ $document->id }}">
                                            @csrf
                                            <label class="visually-hidden" for="document-regenerate-reason-{{ $document->id }}">Motivo de la corrección de {{ $document->code }}</label>
                                            <input type="text" class="form-control form-control-sm w-auto" id="document-regenerate-reason-{{ $document->id }}" name="reason" maxlength="500" required placeholder="Motivo de la corrección" value="{{ old('reason') }}">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Regenerar</button>
                                        </form>
                                    @endif

                                    @if ($canAnnul)
                                        <form method="POST" action="{{ route('course-talks.documents.annul', $document) }}" class="d-flex flex-wrap gap-1 mt-1" data-testid="course-talks-document-annul-form-{{ $document->id }}">
                                            @csrf
                                            <label class="visually-hidden" for="document-annul-reason-{{ $document->id }}">Motivo de la anulación de {{ $document->code }}</label>
                                            <input type="text" class="form-control form-control-sm w-auto" id="document-annul-reason-{{ $document->id }}" name="reason" maxlength="500" required placeholder="Motivo de la anulación" value="{{ old('reason') }}">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Anular</button>
                                        </form>
                                    @endif
                                @endif

                                @php
                                    $documentDeliveries = $deliveries[$document->id] ?? collect();
                                    $pendingHandoff = $pendingHandoffOf($documentDeliveries);
                                @endphp

                                <div class="mt-2 border-top pt-2" data-testid="course-talks-document-delivery-{{ $document->id }}">
                                    <p class="small fw-semibold mb-1">Historial de entregas</p>
                                    @forelse ($documentDeliveries as $delivery)
                                        <div class="small" data-testid="course-talks-document-delivery-{{ $document->id }}-{{ $delivery->id }}">
                                            <span class="badge {{ $channelMeta[$delivery->channel][1] ?? 'text-bg-secondary' }}">{{ $channelMeta[$delivery->channel][0] ?? $delivery->channel }}</span>
                                            <span class="badge {{ $attemptStatusMeta[$delivery->status][1] ?? 'text-bg-secondary' }}">{{ $attemptStatusMeta[$delivery->status][0] ?? $delivery->status }}</span>
                                            <span class="text-secondary">{{ $delivery->recipient_ref }}</span>
                                            <span class="text-secondary">· Intentos: {{ $delivery->attempts }}</span>
                                            <span class="text-secondary">· {{ $delivery->updated_at?->format('d/m/Y H:i') ?? '—' }}</span>
                                            @if ($delivery->last_error)
                                                <div class="text-danger">Error: {{ $delivery->last_error }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="small text-secondary mb-0" data-testid="course-talks-document-delivery-none-{{ $document->id }}">Sin intentos de entrega registrados.</p>
                                    @endforelse

                                    @if ($canSend && $document->status === App\Enums\Courses\AcademicDocumentStatus::Current && ($deliverability[$document->id] ?? false))
                                        {{-- The service's own deliverability verdict decides whether the
                                             actions are offered at all: a current document whose
                                             private file is missing (or whose document row is gone)
                                             would be refused, so no control is offered for it. The
                                             status check above stays as defence in depth, not as the
                                             rule, and a stale page still reaches the service's own
                                             refusal. --}}
                                        @php
                                            // The idempotency keys are minted once per rendered
                                            // form, so a double submit reuses the key the server
                                            // already recorded instead of appending a duplicate
                                            // ledger row. Each channel has its own key because
                                            // the service refuses a key that belongs to another
                                            // channel or recipient.
                                            $emailOperationKey = (string) \Illuminate\Support\Str::uuid();
                                            $whatsappOperationKey = (string) \Illuminate\Support\Str::uuid();
                                            $confirmationOperationKey = (string) \Illuminate\Support\Str::uuid();
                                        @endphp

                                        {{-- Plain HTML forms on purpose: these payloads need their
                                             own POST endpoint and their own FormRequest, and an
                                             interpolation inside a component's attributes would
                                             not be evaluated. --}}
                                        <form method="POST" action="{{ route('course-talks.documents.email', $document) }}" class="d-flex flex-wrap gap-1 mt-2" data-testid="course-talks-document-email-form-{{ $document->id }}">
                                            @csrf
                                            <input type="hidden" name="operation_key" value="{{ $emailOperationKey }}">
                                            <label class="visually-hidden" for="document-email-recipient-{{ $document->id }}">Correo del destinatario de {{ $document->code }}</label>
                                            <input type="email" class="form-control form-control-sm w-auto" id="document-email-recipient-{{ $document->id }}" name="recipient" maxlength="255" required placeholder="correo@ejemplo.com" value="{{ old('recipient', $enrollment->participant->email) }}">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Enviar por correo</button>
                                        </form>

                                        <form method="POST" action="{{ route('course-talks.documents.whatsapp', $document) }}" class="d-flex flex-wrap gap-1 mt-1" data-testid="course-talks-document-whatsapp-form-{{ $document->id }}">
                                            @csrf
                                            <input type="hidden" name="operation_key" value="{{ $whatsappOperationKey }}">
                                            <label class="visually-hidden" for="document-whatsapp-phone-{{ $document->id }}">Teléfono del destinatario de {{ $document->code }}</label>
                                            <input type="tel" class="form-control form-control-sm w-auto" id="document-whatsapp-phone-{{ $document->id }}" name="recipient_phone" maxlength="30" required placeholder="51999999999" value="{{ old('recipient_phone', $enrollment->participant->mobile) }}">
                                            <button type="submit" class="btn btn-sm btn-outline-success">Abrir WhatsApp</button>
                                        </form>

                                        @if ($pendingHandoff)
                                            <form method="POST" action="{{ route('course-talks.documents.whatsapp.confirm', $document) }}" class="d-flex flex-wrap gap-1 mt-1" data-testid="course-talks-document-whatsapp-confirm-form-{{ $document->id }}">
                                                @csrf
                                                <input type="hidden" name="operation_key" value="{{ $confirmationOperationKey }}">
                                                <input type="hidden" name="handoff" value="{{ $pendingHandoff->id }}">
                                                <label class="visually-hidden" for="document-whatsapp-confirm-phone-{{ $document->id }}">Teléfono confirmado de {{ $document->code }}</label>
                                                <input type="tel" class="form-control form-control-sm w-auto" id="document-whatsapp-confirm-phone-{{ $document->id }}" name="recipient_phone" maxlength="30" required value="{{ old('recipient_phone', $pendingHandoff->recipient_ref) }}">
                                                <button type="submit" class="btn btn-sm btn-success">Marcar como enviado</button>
                                            </form>
                                            <p class="small text-secondary mb-0 mt-1" data-testid="course-talks-document-whatsapp-pending-{{ $document->id }}">WhatsApp pendiente de confirmación: use «Marcar como enviado» cuando haya enviado el mensaje.</p>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-secondary small mb-0" data-testid="course-talks-documents-none-{{ $enrollment->id }}">Todavía no se ha generado ningún documento para esta matrícula.</p>
                        @endforelse

                        @if ($canGenerate && $current === null && $result->eligible)
                            <form method="POST" action="{{ route('course-talks.documents.generate', $enrollment) }}" class="mt-2" data-testid="course-talks-documents-generate-form-{{ $enrollment->id }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">Generar documento</button>
                            </form>
                        @elseif ($canGenerate && $current === null)
                            <p class="text-secondary small mb-0 mt-2" data-testid="course-talks-documents-generate-unavailable-{{ $enrollment->id }}">La generación estará disponible cuando se cumplan las condiciones pendientes.</p>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-secondary py-4" data-testid="course-talks-documents-empty">Todavía no hay participantes inscritos en este dictado.</td>
                </tr>
            @endforelse
        @endslot
    </x-table>
@endsection
