{{--
    Documents panel (B09 / RF-DOC-001..005) — embedded into the show view
    of every morph subject (Lead, Customer, Contact, Opportunity, Quotation,
    Activity).

    Inputs (props):
        $subject    Eloquent model instance (Lead | Customer | Contact |
                    Opportunity | Quotation | Activity). Its morph class
                    identifies the upload route automatically.
        $documents  Collection<Document>|null — preloaded by the controller.
                    When null, the panel still renders an upload form but
                    skips the list (used by the standalone activity show).
--}}

@php
    use App\Models\Activity;
    use App\Models\Contact;
    use App\Models\Customer;
    use App\Models\Lead;
    use App\Models\Opportunity;
    use App\Models\Quotation;
    use App\Models\SupportIncidentDetail;
    use App\Models\SupportObservation;
    use App\Models\SupportSessionDetail;
    use App\Models\SupportTicket;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Collection;

    /** @var Model $subject */
    /** @var Collection<int, \App\Models\Document>|null $documents */

    $documents = $documents ?? null;
    $user = auth()->user();

    $uploadUrl = match ($subject::class) {
        Lead::class       => route('leads.documents.store', $subject),
        Customer::class   => route('customers.documents.store', $subject),
        Contact::class    => route('contacts.documents.store', $subject),
        Opportunity::class=> route('opportunities.documents.store', $subject),
        Quotation::class  => route('quotations.documents.store', $subject),
        Activity::class   => route('activities.documents.store', $subject),
        SupportTicket::class => route('support.tickets.documents.store', $subject),
        SupportObservation::class => route('support.tickets.observations.documents.store', [$subject->ticket_id, $subject]),
        SupportIncidentDetail::class => route('support.tickets.incidents.documents.store', [$subject->ticket_id, $subject]),
        SupportSessionDetail::class => route('support.tickets.sessions.documents.store', [$subject->ticket_id, $subject]),
        default           => null,
    };

    $allowedExts = \App\Services\DocumentService::ALLOWED_EXTENSIONS;
    $maxBytes = (int) (\App\Models\Setting::query()->where('key', 'documents.max_size')->value('value')
        ?: \App\Services\DocumentService::DEFAULT_MAX_SIZE_BYTES);
    $maxKb = (int) ceil($maxBytes / 1024);

    $canUpload = $uploadUrl !== null
        && $user !== null
        && $user->can('create', \App\Models\Document::class)
        && $user->can('view', $subject);

    $downloadPerm = $user !== null && $user->can('documents.download');
    $deletePerm = $user !== null && (
        $user->can('documents.view.any') || $user->can('documents.delete')
    );
@endphp

<div class="card mt-3" data-testid="documents-panel">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Documentos</h3>
        <span class="small text-secondary">
            Máx. {{ number_format($maxBytes / 1048576, 1) }} MB ·
            {{ strtoupper(implode(', ', $allowedExts)) }}
        </span>
    </div>
    <div class="card-body">
        @if ($documents !== null && $documents->isNotEmpty())
            <div class="table-responsive mb-3">
                <table class="table table-hover align-middle mb-0" data-testid="documents-table">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Tamaño</th>
                            <th>Subido</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr data-testid="document-row">
                                <td>
                                    {{ $document->name }}
                                    @if ($document->extension)
                                        <span class="badge text-bg-secondary ms-1">.{{ $document->extension }}</span>
                                    @endif
                                </td>
                                <td class="small text-secondary">{{ $document->mime_type }}</td>
                                <td class="small">{{ number_format($document->size_bytes / 1024, 1) }} KB</td>
                                <td class="small text-nowrap">{{ $document->uploaded_at?->format('d/m/Y H:i') }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($downloadPerm && $document->disk && $document->path)
                                        <a href="{{ route('documents.download', $document) }}"
                                           class="btn btn-sm btn-outline-primary"
                                           data-testid="document-download-btn"
                                           title="Descargar">
                                            <i class="bi bi-download me-1" aria-hidden="true"></i>
                                        Descargar</a>
                                    @endif
                                    @if (($deletePerm && (int) $document->uploaded_by === (int) $user?->id) || $user?->can('documents.view.any'))
                                        <x-swal-confirm
                                            :action="route('documents.destroy', $document)"
                                            method="DELETE"
                                            :title="¿Eliminar el documento '{{ $document->name }}'?"
                                            text="Esta acción no se puede deshacer."
                                            type="warning"
                                            confirm-text="Sí, eliminar"
                                            button-class="btn-sm btn-outline-danger"
                                            title="Eliminar"
                                            class="d-inline">
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                            Eliminar
                                        </x-swal-confirm>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($documents !== null)
            <p class="text-secondary small mb-3" data-testid="documents-empty">
                Sin documentos adjuntos.
            </p>
        @endif

        @if ($canUpload)
            <form method="POST"
                  action="{{ $uploadUrl }}"
                  enctype="multipart/form-data"
                  class="d-flex flex-wrap gap-2 align-items-end"
                  data-testid="document-upload-form"
                  data-swal-loading>
                @csrf
                <div class="flex-grow-1">
                    <label for="document-file-{{ $subject->getKey() }}" class="form-label small mb-1">
                        Subir nuevo documento
                    </label>
                    <input type="file"
                           id="document-file-{{ $subject->getKey() }}"
                           name="file"
                           class="form-control @error('file') is-invalid @enderror"
                           accept=".{{ implode(',.', $allowedExts) }}"
                           data-testid="document-file-input"
                           required>
                    <x-validation-error name="file"/>
                </div>
                <button type="submit" class="btn btn-primary" data-testid="document-upload-btn">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i> Subir
                </button>
            </form>
        @else
            <p class="text-secondary small mb-0">No tiene permisos para subir documentos aquí.</p>
        @endif
    </div>
</div>