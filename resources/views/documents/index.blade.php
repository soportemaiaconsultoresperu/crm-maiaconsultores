@extends('layouts.app')

@section('title', 'Documentos')
@section('page-title', 'Documentos')

@section('content')
    <div class="card lead-form-hero mb-3">
        <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <p class="text-uppercase text-secondary small mb-1">Gestión documental</p>
                <h2 class="h4 mb-1">Documentos del CRM</h2>
                <p class="text-secondary mb-0">Consultá, descargá o eliminá archivos adjuntos a prospectos, clientes, oportunidades, cotizaciones y actividades.</p>
            </div>
            <span class="dashboard-kpi-icon dashboard-kpi-icon-blue" aria-hidden="true"><i class="bi bi-folder2-open"></i></span>
        </div>
    </div>

    <x-table id="documents-index" title="Últimos documentos" data-testid="documents-index-table">
        <x-slot:filters>
            <p class="small text-secondary mb-0">Se muestran los últimos documentos disponibles según tus permisos.</p>
        </x-slot:filters>
        <x-slot:headers>
            <tr>
                <th>Documento</th>
                <th>Relacionado con</th>
                <th>Tipo</th>
                <th>Tamaño</th>
                <th>Subido por</th>
                <th>Fecha</th>
                <th class="text-end">Acciones</th>
            </tr>
        </x-slot:headers>
        <x-slot:rows>
            @forelse ($documents as $document)
                @php
                    $subject = $document->docable;
                    $subjectClass = $subject ? $subject::class : null;
                    $subjectLabel = $subject ? class_basename($subject).' #'.$subject->getKey() : 'Registro no disponible';
                    $subjectUrl = match ($subjectClass) {
                        \App\Models\Lead::class => route('leads.show', $subject),
                        \App\Models\Customer::class => route('customers.show', $subject),
                        \App\Models\Opportunity::class => route('opportunities.show', $subject),
                        \App\Models\Quotation::class => route('quotations.show', $subject),
                        \App\Models\Activity::class => route('activities.show', $subject),
                        \App\Models\SupportTicket::class => route('support.tickets.show', $subject),
                        default => null,
                    };
                    $canDeleteDocument = auth()->user()?->can('documents.view.any')
                        || ((int) $document->uploaded_by === (int) auth()->id() && auth()->user()?->can('documents.delete'));
                @endphp
                <tr data-testid="document-row">
                    <td>
                        <span class="fw-medium">{{ $document->name }}</span>
                        @if ($document->extension)
                            <span class="badge text-bg-secondary ms-1">.{{ $document->extension }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($subjectUrl)
                            <a href="{{ $subjectUrl }}">{{ $subjectLabel }}</a>
                        @else
                            <span class="text-secondary">{{ $subjectLabel }}</span>
                        @endif
                    </td>
                    <td class="small text-secondary">{{ $document->mime_type }}</td>
                    <td class="small text-nowrap">{{ number_format($document->size_bytes / 1024, 1) }} KB</td>
                    <td>{{ $document->uploader?->name ?? '—' }}</td>
                    <td class="text-nowrap">{{ $document->uploaded_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        @if (auth()->user()?->can('documents.download') && $document->disk && $document->path)
                            <a href="{{ route('documents.download', $document) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-download me-1" aria-hidden="true"></i>Descargar
                            </a>
                        @endif
                        @if ($canDeleteDocument)
                            <x-swal-confirm
                                :action="route('documents.destroy', $document)"
                                method="DELETE"
                                title="¿Eliminar documento?"
                                text="Esta acción no se puede deshacer."
                                type="warning"
                                confirm-text="Sí, eliminar"
                                button-class="btn-sm btn-outline-danger"
                                class="d-inline">
                                <i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar
                            </x-swal-confirm>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @include('layouts.partials.empty-state', [
                            'message' => 'No hay documentos disponibles.',
                            'hint' => 'Subí archivos desde la ficha de un prospecto, cliente, oportunidad, cotización o actividad.',
                        ])
                    </td>
                </tr>
            @endforelse
        </x-slot:rows>
    </x-table>
@endsection
