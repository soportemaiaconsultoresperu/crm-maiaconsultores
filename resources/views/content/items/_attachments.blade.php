{{--
    Attachments panel (T7) for a content item. Expected data:
    $project, $item, $attachments (ordered active rows), $attachmentLimits and,
    optionally, $retiredAttachments (retired rows that can be restored).

    Spanish copy only. The accepted formats and limits come from
    ContentAttachmentService::limits(), so the screen can never drift from what
    the server enforces.
--}}
@php
    $megabytes = fn (int $bytes): string => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    $canUpdate = auth()->user()?->can('update', $project) ?? false;
    // Restoring a retired attachment mirrors the `trash` ability, like
    // restoring a content (members included, refused on an archived project).
    $canRestore = auth()->user()?->can('trash', $project) ?? false;
    $retiredAttachments ??= collect();
@endphp

<div class="card mt-3" id="attachments" data-testid="content-attachments">
    <div class="card-header">
        <h3 class="card-title mb-0">Archivos y enlaces</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Imágenes permitidas: {{ implode(', ', $attachmentLimits['image_extensions']) }}
            (máximo {{ $megabytes($attachmentLimits['image_max_bytes']) }} MB cada una).
            Videos permitidos: {{ implode(', ', $attachmentLimits['video_extensions']) }}
            (máximo {{ $megabytes($attachmentLimits['video_max_bytes']) }} MB cada uno).
            Máximo {{ $attachmentLimits['max_per_content'] }} adjuntos por contenido; los enlaces también cuentan.
            Los archivos se guardan de forma privada y sólo se descargan desde aquí.
            También se acepta cualquier enlace http o https: si el enlace apunta a una imagen o un video se identifica como tal y, si no, se guarda como enlace y se abre fuera del CRM.
        </p>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($attachments->isEmpty())
            <p class="text-muted" data-testid="content-attachments-empty">Este contenido todavía no tiene archivos ni enlaces.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Orden</th>
                            <th scope="col">Adjunto</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Tamaño</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attachments as $attachment)
                            <tr data-testid="content-attachment-{{ $attachment->id }}">
                                <td style="width: 9rem;">
                                    @if ($canUpdate)
                                        <form method="POST"
                                              action="{{ route('content.projects.contents.attachments.reorder', [$project, $item, $attachment]) }}"
                                              class="d-flex gap-1">
                                            @csrf
                                            <input type="number" name="sort" value="{{ $attachment->sort }}" min="0" max="2147483647"
                                                   class="form-control form-control-sm" aria-label="Orden de {{ $attachment->original_name ?? $attachment->url }}" required>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                                        </form>
                                    @else
                                        {{ $attachment->sort }}
                                    @endif
                                </td>
                                <td>
                                    @if ($attachment->original_name)
                                        <span class="fw-semibold">{{ $attachment->original_name }}</span>
                                    @endif
                                    @if ($attachment->isLink())
                                        <div class="small text-break"><a href="{{ $attachment->url }}" target="_blank" rel="noopener noreferrer">{{ $attachment->url }}</a></div>
                                    @endif
                                </td>
                                <td>
                                    @if ($attachment->isVideo())
                                        <span class="badge text-bg-dark" data-testid="content-attachment-type-video">Video</span>
                                    @elseif ($attachment->isImage())
                                        <span class="badge text-bg-info" data-testid="content-attachment-type-image">Imagen</span>
                                    @else
                                        <span class="badge text-bg-secondary" data-testid="content-attachment-type-link">Enlace</span>
                                    @endif
                                    @if ($attachment->isUpload())
                                        <span class="badge text-bg-secondary">Archivo</span>
                                    @elseif ($attachment->isImage() || $attachment->isVideo())
                                        <span class="badge text-bg-secondary">Enlace</span>
                                    @endif
                                    @if ($attachment->isGenericLink())
                                        <div class="small text-muted">Se abre fuera del CRM.</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($attachment->isUpload())
                                        {{ number_format(($attachment->size_bytes ?? 0) / 1024, 0) }} KB
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        @if ($attachment->isUpload())
                                            <a href="{{ route('content.projects.contents.attachments.download', [$project, $item, $attachment]) }}"
                                               class="btn btn-sm btn-outline-secondary">Descargar</a>
                                        @endif
                                        @if ($canUpdate)
                                            <form method="POST"
                                                  action="{{ route('content.projects.contents.attachments.retire', [$project, $item, $attachment]) }}"
                                                  onsubmit="return confirm('¿Retirar este adjunto? El archivo se conserva y podrá recuperarlo con «Restaurar adjunto».');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-warning">Retirar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($retiredAttachments->isNotEmpty())
            <h4 class="h6 mt-3">Adjuntos retirados</h4>
            <p class="text-muted small">
                Un adjunto retirado no se muestra en el contenido ni cuenta para el máximo, pero conserva su archivo.
                Puede volver a usarlo con «Restaurar adjunto» mientras haya espacio para un adjunto más.
            </p>
            <ul class="list-group mb-3" data-testid="content-attachments-retired">
                @foreach ($retiredAttachments as $retired)
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2" data-testid="content-attachment-retired-{{ $retired->id }}">
                        <span class="text-break">
                            {{ $retired->original_name ?: $retired->url }}
                            <span class="badge text-bg-secondary ms-1">Retirado</span>
                        </span>
                        <span class="d-inline-flex gap-1">
                            @if ($retired->isUpload())
                                <a href="{{ route('content.projects.contents.attachments.download', [$project, $item, $retired]) }}"
                                   class="btn btn-sm btn-outline-secondary">Descargar</a>
                            @endif
                            @if ($canRestore)
                                <form method="POST" action="{{ route('content.projects.contents.attachments.restore', [$project, $item, $retired]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" data-testid="content-attachment-restore-{{ $retired->id }}">
                                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restaurar adjunto
                                    </button>
                                </form>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canUpdate)
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <form method="POST"
                          action="{{ route('content.projects.contents.attachments.store', [$project, $item]) }}"
                          enctype="multipart/form-data"
                          class="border rounded p-3 h-100"
                          data-testid="content-attachment-upload-form">
                        @csrf
                        <h4 class="h6">Subir archivo</h4>
                        <label for="attachment-file" class="form-label">Archivo</label>
                        <input type="file" name="file" id="attachment-file"
                               class="form-control @error('file') is-invalid @enderror"
                               accept=".jpg,.jpeg,.png,.webp,.mp4,.mov">
                        <x-validation-error name="file"/>
                        <button type="submit" class="btn btn-primary mt-2">Adjuntar archivo</button>
                    </form>
                </div>
                <div class="col-md-6">
                    <form method="POST"
                          action="{{ route('content.projects.contents.attachments.link', [$project, $item]) }}"
                          class="border rounded p-3 h-100"
                          data-testid="content-attachment-link-form">
                        @csrf
                        <h4 class="h6">Adjuntar enlace</h4>
                        <label for="attachment-url" class="form-label">Enlace a imagen, video o recurso externo</label>
                        <input type="url" name="url" id="attachment-url" value="{{ old('url') }}"
                               class="form-control @error('url') is-invalid @enderror"
                               placeholder="https://ejemplo.com/recurso" maxlength="2048">
                        <div class="form-text">Se acepta cualquier enlace http o https. Si no apunta a una imagen o un video, se guarda como enlace y se abre fuera del CRM.</div>
                        <x-validation-error name="url"/>
                        <button type="submit" class="btn btn-outline-primary mt-2">Adjuntar enlace</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
