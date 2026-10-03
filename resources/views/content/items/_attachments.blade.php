{{--
    Attachments panel (T7) for a content item. Expected data:
    $project, $item, $attachments (ordered active rows), $attachmentLimits.

    Spanish copy only. The accepted formats and limits come from
    ContentAttachmentService::limits(), so the screen can never drift from what
    the server enforces.
--}}
@php
    $megabytes = fn (int $bytes): string => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    $canUpdate = auth()->user()?->can('update', $project) ?? false;
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
                                    <span class="fw-semibold">{{ $attachment->original_name ?? $attachment->url }}</span>
                                    @if ($attachment->isLink())
                                        <div class="small text-break"><a href="{{ $attachment->url }}" target="_blank" rel="noopener noreferrer">{{ $attachment->url }}</a></div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $attachment->isVideo() ? 'text-bg-dark' : 'text-bg-info' }}">
                                        {{ $attachment->isVideo() ? 'Video' : 'Imagen' }}
                                    </span>
                                    @if ($attachment->isLink())
                                        <span class="badge text-bg-secondary">Enlace</span>
                                    @else
                                        <span class="badge text-bg-secondary">Archivo</span>
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
                                                  onsubmit="return confirm('¿Retirar este adjunto? El archivo se conserva y podrá restaurarse con el contenido.');">
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
                        <label for="attachment-url" class="form-label">Enlace a imagen o video</label>
                        <input type="url" name="url" id="attachment-url" value="{{ old('url') }}"
                               class="form-control @error('url') is-invalid @enderror"
                               placeholder="https://ejemplo.com/imagen.jpg" maxlength="2048">
                        <x-validation-error name="url"/>
                        <button type="submit" class="btn btn-outline-primary mt-2">Adjuntar enlace</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
