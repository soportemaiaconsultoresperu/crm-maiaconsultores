@extends('layouts.app')

@section('title', 'Comprobantes comerciales de la edición')
@section('page-title', 'Comprobantes comerciales de la edición')

@section('content')
    @php
        // Presentation-only maps: the stored type, the document status and the
        // delivery status are decided by the domain (CommercialDocumentType,
        // DeliveryStatus and the document's own status column); this surface
        // only renders them in Spanish. No amount below is computed, rounded or
        // reformatted here — every money value is the exact string the service
        // returned or persisted.
        $typeLabels = [
            'factura' => 'Factura',
            'boleta' => 'Boleta',
            'recibo' => 'Recibo',
        ];
        $statusMeta = [
            'pending_file' => ['Pendiente de archivo', 'text-bg-warning'],
            'registered' => ['Registrado', 'text-bg-success'],
            'sent' => ['Enviado', 'text-bg-primary'],
            'discarded' => ['Descartado', 'text-bg-light'],
        ];
        $deliveryStatusMeta = [
            'pending' => ['Entrega pendiente', 'text-bg-secondary'],
            'sent' => ['Enviado', 'text-bg-success'],
            'failed' => ['Entrega fallida', 'text-bg-danger'],
            'discarded' => ['Descartado', 'text-bg-light'],
        ];
        $fallbackMeta = static fn (string $value): array => [str_replace('_', ' ', $value), 'text-bg-secondary'];

        // Registration and upload require exactly the ability their own routes,
        // their FormRequests and CourseCommercialDocumentService ask for, so no
        // rendered control can answer 403 and no commercial money breakdown is
        // offered to a user who cannot register one either.
        $canManage = Gate::allows('manage', App\Models\Courses\CourseCommercialDocument::class);

        // Same rule for the delivery actions: `send` on the commercial document
        // (which maps to `course-talks.documents.send`) is the only ability their
        // routes, their FormRequests and CourseDocumentDeliveryService ask for, so
        // the delivery controls are gated on exactly that ability and none of them
        // can answer 403.
        $canSend = Gate::allows('send', App\Models\Courses\CourseCommercialDocument::class);

        // Attempt-level presentation maps. The append-only outbound-delivery
        // ledger is the only source of delivery truth: it decides the channel, the
        // status, the attempt count, the recipient reference and the error, and
        // this surface only renders those values in Spanish.
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

        // The recipient prefill comes from the real data the domain already holds
        // for the comprobante's only target: the participant of its enrollment, or
        // the payer customer of its enrollment group. Nothing is invented here.
        $recipientOf = static function ($commercial): array {
            $participant = $commercial->enrollment?->participant;
            if ($participant !== null) {
                return ['email' => (string) $participant->email, 'phone' => (string) $participant->mobile];
            }

            $payer = $commercial->group?->payerCustomer;

            return ['email' => (string) ($payer?->email ?? ''), 'phone' => (string) ($payer?->phone ?? '')];
        };

        // Presentation-only guard: only a comprobante the service could accept
        // offers delivery controls. Whether its private file is really streamable
        // stays the service's rule, which refuses with a visible Spanish error
        // instead of a silent failure.
        $deliverableStatuses = ['registered', 'sent'];
    @endphp

    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary mb-3">Volver a la edición</a>

    <div class="card mb-3" data-testid="course-talks-commercial-edition">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Actividad</dt>
                <dd class="col-sm-9">{{ $edition->activity->name }} · {{ $edition->activity->type->label() }}</dd>
                <dt class="col-sm-3">Edición</dt>
                <dd class="col-sm-9"><code>{{ $edition->code ?: '—' }}</code> · {{ $edition->modality->label() }}</dd>
                <dt class="col-sm-3">Comprobantes registrados</dt>
                <dd class="col-sm-9">{{ $commercialDocuments->count() }}</dd>
            </dl>
        </div>
    </div>

    {{-- A rejected action is reported under the `commercial_document` key and the
         payload errors (type, target, file) under their own keys. Listing every
         message keeps all of them visible. --}}
    @if ($errors->any())
        <x-alert type="error" data-testid="course-talks-commercial-errors">
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <x-table title="Comprobantes comerciales de la edición">
        @slot('headers')
            <tr>
                <th scope="col">Comprobante</th>
                <th scope="col">Pagador</th>
                <th scope="col">Serie y número</th>
                <th scope="col">Emisión</th>
                <th scope="col">Moneda</th>
                <th scope="col" class="text-end">Subtotal</th>
                <th scope="col" class="text-end">Tasa IGV</th>
                <th scope="col" class="text-end">IGV</th>
                <th scope="col" class="text-end">Total</th>
                <th scope="col">Estado</th>
                <th scope="col">Entrega</th>
                <th scope="col">Adjunto privado</th>
            </tr>
        @endslot
        @slot('rows')
            @forelse ($commercialDocuments as $commercial)
                @php
                    $statusEntry = $statusMeta[$commercial->status] ?? $fallbackMeta($commercial->status);
                    $deliveryEntry = $deliveryStatusMeta[$commercial->delivery_status->value] ?? $fallbackMeta($commercial->delivery_status->value);
                @endphp
                <tr data-testid="course-talks-commercial-row-{{ $commercial->id }}">
                    <td>
                        <strong>{{ $typeLabels[$commercial->type->value] ?? $commercial->type->value }}</strong>
                        @if ($commercial->observations)
                            <div class="text-secondary small">{{ $commercial->observations }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $commercial->payer_name }}
                        @if ($commercial->payer_document_number)
                            <div class="text-secondary small">{{ $commercial->payer_document_type }} {{ $commercial->payer_document_number }}</div>
                        @endif
                        @if ($commercial->group)
                            <div class="text-secondary small">Grupo: {{ $commercial->group->payer_name }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $commercial->series ?: '—' }} / {{ $commercial->number ?: '—' }}
                    </td>
                    <td>{{ $commercial->issue_date?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $commercial->currency }}</td>
                    <td class="text-end">{{ $commercial->subtotal_amount }}</td>
                    <td class="text-end">{{ $commercial->igv_rate }}</td>
                    <td class="text-end">{{ $commercial->igv_amount }}</td>
                    <td class="text-end fw-semibold">{{ $commercial->total_amount }}</td>
                    <td><span class="badge {{ $statusEntry[1] }}">{{ $statusEntry[0] }}</span></td>
                    <td>
                        <span class="badge {{ $deliveryEntry[1] }}">{{ $deliveryEntry[0] }}</span>
                        <div class="text-secondary small">{{ $commercial->last_sent_at?->format('d/m/Y H:i') ?? 'Sin envíos' }}</div>
                    </td>
                    <td data-testid="course-talks-commercial-attachment-{{ $commercial->id }}">
                        @if ($commercial->document)
                            <span class="badge text-bg-success">Adjunto cargado</span>
                            <div class="text-secondary small">{{ $commercial->document->name }}</div>
                        @else
                            <span class="badge text-bg-warning">Sin adjunto</span>
                            @if ($canManage)
                                {{-- Plain HTML form on purpose: an interpolation inside
                                     a Blade component's attributes is not evaluated,
                                     and the id must stay unique per document. --}}
                                <form method="POST" action="{{ route('course-talks.commercial-documents.file', $commercial) }}" enctype="multipart/form-data" class="mt-2" data-testid="course-talks-commercial-upload-form-{{ $commercial->id }}">
                                    @csrf
                                    <input type="hidden" name="status" value="registered">
                                    <label class="visually-hidden" for="commercial-file-{{ $commercial->id }}">Archivo emitido del comprobante {{ $commercial->series }} {{ $commercial->number }}</label>
                                    <input type="file" class="form-control form-control-sm" id="commercial-file-{{ $commercial->id }}" name="file" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary mt-1">Adjuntar archivo</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
                @php
                    $documentDeliveries = $deliveries[$commercial->id] ?? collect();
                    $pendingHandoff = $pendingHandoffOf($documentDeliveries);
                    $recipient = $recipientOf($commercial);
                    $deliveryLabel = ($typeLabels[$commercial->type->value] ?? $commercial->type->value).' '.($commercial->series ?: '—').'-'.($commercial->number ?: '—');
                @endphp
                <tr data-testid="course-talks-commercial-delivery-row-{{ $commercial->id }}">
                    <td colspan="12" class="bg-body-tertiary">
                        <div data-testid="course-talks-commercial-delivery-{{ $commercial->id }}">
                            <p class="small fw-semibold mb-1">Historial de entregas</p>
                            @forelse ($documentDeliveries as $delivery)
                                <div class="small" data-testid="course-talks-commercial-delivery-{{ $commercial->id }}-{{ $delivery->id }}">
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
                                <p class="small text-secondary mb-0" data-testid="course-talks-commercial-delivery-none-{{ $commercial->id }}">Sin intentos de entrega registrados.</p>
                            @endforelse
                        </div>

                        @if ($canSend && in_array($commercial->status, $deliverableStatuses, true))
                            @php
                                // The idempotency keys are minted once per rendered form, so a
                                // double submit reuses the key the server already recorded
                                // instead of appending a duplicate ledger row. Each channel has
                                // its own key because the service refuses a key that belongs to
                                // another channel or recipient.
                                $emailOperationKey = (string) \Illuminate\Support\Str::uuid();
                                $whatsappOperationKey = (string) \Illuminate\Support\Str::uuid();
                                $confirmationOperationKey = (string) \Illuminate\Support\Str::uuid();
                            @endphp

                            <div class="mt-2 border-top pt-2">
                                <p class="small fw-semibold mb-1">Acciones de entrega de {{ $deliveryLabel }}</p>

                                {{-- Plain HTML forms on purpose: these payloads need their own POST
                                     endpoint and their own FormRequest, and an interpolation inside a
                                     component's attributes would not be evaluated. --}}
                                <form method="POST" action="{{ route('course-talks.commercial-documents.email', $commercial) }}" class="d-flex flex-wrap gap-1 mt-1" data-testid="course-talks-commercial-email-form-{{ $commercial->id }}">
                                    @csrf
                                    <input type="hidden" name="operation_key" value="{{ $emailOperationKey }}">
                                    <label class="visually-hidden" for="commercial-email-recipient-{{ $commercial->id }}">Correo del destinatario de {{ $deliveryLabel }}</label>
                                    <input type="email" class="form-control form-control-sm w-auto" id="commercial-email-recipient-{{ $commercial->id }}" name="recipient" maxlength="255" required placeholder="correo@ejemplo.com" value="{{ old('recipient', $recipient['email']) }}">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Enviar por correo</button>
                                </form>

                                <form method="POST" action="{{ route('course-talks.commercial-documents.whatsapp', $commercial) }}" class="d-flex flex-wrap gap-1 mt-1" data-testid="course-talks-commercial-whatsapp-form-{{ $commercial->id }}">
                                    @csrf
                                    <input type="hidden" name="operation_key" value="{{ $whatsappOperationKey }}">
                                    <label class="visually-hidden" for="commercial-whatsapp-phone-{{ $commercial->id }}">Teléfono del destinatario de {{ $deliveryLabel }}</label>
                                    <input type="tel" class="form-control form-control-sm w-auto" id="commercial-whatsapp-phone-{{ $commercial->id }}" name="recipient_phone" maxlength="30" required placeholder="51999999999" value="{{ old('recipient_phone', $recipient['phone']) }}">
                                    <button type="submit" class="btn btn-sm btn-outline-success">Abrir WhatsApp</button>
                                </form>

                                @if ($pendingHandoff)
                                    <form method="POST" action="{{ route('course-talks.commercial-documents.whatsapp.confirm', $commercial) }}" class="d-flex flex-wrap gap-1 mt-1" data-testid="course-talks-commercial-whatsapp-confirm-form-{{ $commercial->id }}">
                                        @csrf
                                        <input type="hidden" name="operation_key" value="{{ $confirmationOperationKey }}">
                                        <input type="hidden" name="handoff" value="{{ $pendingHandoff->id }}">
                                        <label class="visually-hidden" for="commercial-whatsapp-confirm-phone-{{ $commercial->id }}">Teléfono confirmado de {{ $deliveryLabel }}</label>
                                        <input type="tel" class="form-control form-control-sm w-auto" id="commercial-whatsapp-confirm-phone-{{ $commercial->id }}" name="recipient_phone" maxlength="30" required value="{{ old('recipient_phone', $pendingHandoff->recipient_ref) }}">
                                        <button type="submit" class="btn btn-sm btn-success">Marcar como enviado</button>
                                    </form>
                                    <p class="small text-secondary mb-0 mt-1" data-testid="course-talks-commercial-whatsapp-pending-{{ $commercial->id }}">WhatsApp pendiente de confirmación: use «Marcar como enviado» cuando haya enviado el mensaje.</p>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center text-secondary py-4" data-testid="course-talks-commercial-empty">Todavía no se ha registrado ningún comprobante comercial en esta edición.</td>
                </tr>
            @endforelse
        @endslot
    </x-table>

    @if ($canManage)
        <h2 class="h5 mt-4">Registrar comprobante por matrícula</h2>
        <p class="text-secondary small">
            El desglose lo calcula el sistema con el precio de la actividad, el cargo por certificado y el descuento de cada
            matrícula. La compra de un grupo con un solo pagador se factura en la sección de grupos.
        </p>

        @forelse ($enrollments as $enrollment)
            <div class="card mb-3" data-testid="course-talks-commercial-enrollment-{{ $enrollment->id }}">
                <div class="card-header">
                    <h3 class="card-title mb-0">{{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</h3>
                </div>
                <div class="card-body">
                    @if (isset($breakdownFailures[$enrollment->id]))
                        <x-alert type="warning" data-testid="course-talks-commercial-breakdown-error-{{ $enrollment->id }}">
                            {{ $breakdownFailures[$enrollment->id] }}
                        </x-alert>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-3">
                                <caption class="visually-hidden">Desglose calculado por el sistema para cada tipo de comprobante</caption>
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Comprobante</th>
                                        <th scope="col" class="text-end">Subtotal</th>
                                        <th scope="col" class="text-end">Tasa IGV</th>
                                        <th scope="col" class="text-end">IGV</th>
                                        <th scope="col" class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($types as $type)
                                        @php $breakdown = $breakdowns[$enrollment->id][$type->value]; @endphp
                                        <tr data-testid="course-talks-commercial-breakdown-{{ $enrollment->id }}-{{ $type->value }}">
                                            <td>{{ $typeLabels[$type->value] ?? $type->value }}</td>
                                            <td class="text-end">{{ $breakdown['subtotal_amount'] }}</td>
                                            <td class="text-end">{{ $breakdown['igv_rate'] }}</td>
                                            <td class="text-end">{{ $breakdown['igv_amount'] }}</td>
                                            <td class="text-end fw-semibold">{{ $breakdown['total_amount'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <form method="POST" action="{{ route('course-talks.commercial-documents.store', $enrollment) }}" data-testid="course-talks-commercial-form-{{ $enrollment->id }}">
                            @csrf
                            {{-- The endpoint already owns the target, and the request
                                 contract still validates it, so the both/neither rule
                                 stays where Slice 4 put it. --}}
                            <input type="hidden" name="course_enrollment_id" value="{{ $enrollment->id }}">

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="commercial-type-{{ $enrollment->id }}">Tipo de comprobante</label>
                                    <select class="form-select" id="commercial-type-{{ $enrollment->id }}" name="type" required>
                                        @foreach ($types as $type)
                                            <option value="{{ $type->value }}" @selected(old('type', 'factura') === $type->value)>{{ $typeLabels[$type->value] ?? $type->value }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="commercial-payer-name-{{ $enrollment->id }}">Pagador (nombre o razón social)</label>
                                    <input type="text" class="form-control" id="commercial-payer-name-{{ $enrollment->id }}" name="payer_name" maxlength="255" required value="{{ old('payer_name') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-payer-document-type-{{ $enrollment->id }}">Tipo de documento</label>
                                    <input type="text" class="form-control" id="commercial-payer-document-type-{{ $enrollment->id }}" name="payer_document_type" maxlength="30" value="{{ old('payer_document_type') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-payer-document-number-{{ $enrollment->id }}">Número de documento</label>
                                    <input type="text" class="form-control" id="commercial-payer-document-number-{{ $enrollment->id }}" name="payer_document_number" maxlength="50" value="{{ old('payer_document_number') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-series-{{ $enrollment->id }}">Serie</label>
                                    <input type="text" class="form-control" id="commercial-series-{{ $enrollment->id }}" name="series" maxlength="30" value="{{ old('series') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-number-{{ $enrollment->id }}">Número</label>
                                    <input type="text" class="form-control" id="commercial-number-{{ $enrollment->id }}" name="number" maxlength="50" value="{{ old('number') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="commercial-issue-date-{{ $enrollment->id }}">Fecha de emisión</label>
                                    <input type="date" class="form-control" id="commercial-issue-date-{{ $enrollment->id }}" name="issue_date" value="{{ old('issue_date') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="commercial-currency-{{ $enrollment->id }}">Moneda</label>
                                    <input type="text" class="form-control" id="commercial-currency-{{ $enrollment->id }}" name="currency" maxlength="3" value="{{ old('currency', $currency) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="commercial-observations-{{ $enrollment->id }}">Observaciones</label>
                                    <textarea class="form-control" id="commercial-observations-{{ $enrollment->id }}" name="observations" rows="2">{{ old('observations') }}</textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary mt-3">Registrar comprobante</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-alert type="info" data-testid="course-talks-commercial-enrollments-empty">Todavía no hay participantes inscritos en esta edición.</x-alert>
        @endforelse

        <h2 class="h5 mt-4" id="grupos-de-matricula">Registrar comprobante por grupo</h2>
        <p class="text-secondary small">
            El desglose lo calcula el sistema sumando el precio de la actividad, el cargo por certificado y el descuento de
            las matrículas facturables de cada grupo. El pagador es el propio grupo.
        </p>

        @forelse ($groups as $group)
            <div class="card mb-3" data-testid="course-talks-commercial-group-{{ $group->id }}">
                <div class="card-header">
                    <h3 class="card-title mb-0">{{ $group->payer_name }}</h3>
                </div>
                <div class="card-body">
                    @if (isset($groupBreakdownFailures[$group->id]))
                        {{-- The service refuses to bill this group and says why, so
                             the surface shows the reason instead of a control that
                             could only answer with an error. --}}
                        <x-alert type="warning" :data-testid="'course-talks-commercial-group-error-'.$group->id">
                            {{ $groupBreakdownFailures[$group->id] }}
                        </x-alert>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-3">
                                <caption class="visually-hidden">Desglose calculado por el sistema para cada tipo de comprobante del grupo</caption>
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Comprobante</th>
                                        <th scope="col" class="text-end">Subtotal</th>
                                        <th scope="col" class="text-end">Tasa IGV</th>
                                        <th scope="col" class="text-end">IGV</th>
                                        <th scope="col" class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($types as $type)
                                        @php $groupBreakdown = $groupBreakdowns[$group->id][$type->value]; @endphp
                                        <tr data-testid="course-talks-commercial-group-breakdown-{{ $group->id }}-{{ $type->value }}">
                                            <td>{{ $typeLabels[$type->value] ?? $type->value }}</td>
                                            <td class="text-end">{{ $groupBreakdown['subtotal_amount'] }}</td>
                                            <td class="text-end">{{ $groupBreakdown['igv_rate'] }}</td>
                                            <td class="text-end">{{ $groupBreakdown['igv_amount'] }}</td>
                                            <td class="text-end fw-semibold">{{ $groupBreakdown['total_amount'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <form method="POST" action="{{ route('course-talks.commercial-documents.groups.store', $group) }}" data-testid="course-talks-commercial-group-form-{{ $group->id }}">
                            @csrf
                            {{-- The endpoint already owns the target, and the request
                                 contract still validates it, so the both/neither rule
                                 stays where Slice 4 put it. --}}
                            <input type="hidden" name="course_enrollment_group_id" value="{{ $group->id }}">

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="commercial-group-type-{{ $group->id }}">Tipo de comprobante</label>
                                    <select class="form-select" id="commercial-group-type-{{ $group->id }}" name="type" required>
                                        @foreach ($types as $type)
                                            <option value="{{ $type->value }}" @selected(old('type', 'factura') === $type->value)>{{ $typeLabels[$type->value] ?? $type->value }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="commercial-group-payer-name-{{ $group->id }}">Pagador (nombre o razón social)</label>
                                    <input type="text" class="form-control" id="commercial-group-payer-name-{{ $group->id }}" name="payer_name" maxlength="255" required value="{{ old('payer_name', $group->payer_name) }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-group-payer-document-type-{{ $group->id }}">Tipo de documento</label>
                                    <input type="text" class="form-control" id="commercial-group-payer-document-type-{{ $group->id }}" name="payer_document_type" maxlength="30" value="{{ old('payer_document_type', $group->payer_document_type) }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-group-payer-document-number-{{ $group->id }}">Número de documento</label>
                                    <input type="text" class="form-control" id="commercial-group-payer-document-number-{{ $group->id }}" name="payer_document_number" maxlength="50" value="{{ old('payer_document_number', $group->payer_document_number) }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-group-series-{{ $group->id }}">Serie</label>
                                    <input type="text" class="form-control" id="commercial-group-series-{{ $group->id }}" name="series" maxlength="30" value="{{ old('series') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="commercial-group-number-{{ $group->id }}">Número</label>
                                    <input type="text" class="form-control" id="commercial-group-number-{{ $group->id }}" name="number" maxlength="50" value="{{ old('number') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="commercial-group-issue-date-{{ $group->id }}">Fecha de emisión</label>
                                    <input type="date" class="form-control" id="commercial-group-issue-date-{{ $group->id }}" name="issue_date" value="{{ old('issue_date') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="commercial-group-currency-{{ $group->id }}">Moneda</label>
                                    <input type="text" class="form-control" id="commercial-group-currency-{{ $group->id }}" name="currency" maxlength="3" value="{{ old('currency', $currency) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="commercial-group-observations-{{ $group->id }}">Observaciones</label>
                                    <textarea class="form-control" id="commercial-group-observations-{{ $group->id }}" name="observations" rows="2">{{ old('observations') }}</textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary mt-3">Registrar comprobante del grupo</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-alert type="info" data-testid="course-talks-commercial-groups-empty">Todavía no hay grupos de matrícula en esta edición.</x-alert>
        @endforelse
    @endif
@endsection
