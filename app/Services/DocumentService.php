<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents service (B09 / RF-DOC-001..005, ADR-008, ADR-011).
 *
 * Files always live on the PRIVATE `docs` disk (storage/app/private/docs).
 * There is NEVER a public symlink for documents; downloads must go through
 * this service so authorization and audit happen. The disk entry is defined
 * in config/filesystems.php with `visibility => 'private'`.
 *
 * Validation surface (RF-DOC-001, RNF-SEG-002):
 *   - extension whitelist (pdf, doc, docx, xls, xlsx, jpg, jpeg, png, txt)
 *   - MIME cross-checked against extension (Laravel `mimes:` rule)
 *   - size limit from `documents.max_size` setting (default 10 MB)
 *   - upload actor is the only `uploaded_by`; downloads log IP + actor
 *
 * Every state change is audited through spatie/laravel-activitylog
 * (ADR-008): document-uploaded / document-downloaded / document-deleted.
 */
class DocumentService
{
    /**
     * Whitelisted extensions (dot stripped, lowercased).
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt',
    ];

    /**
     * Default upper bound when the `documents.max_size` setting is absent.
     * Documented in docs/DECISIONES.md ADR-011; overridable from settings.
     */
    public const DEFAULT_MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Upload a file and attach it as a Document to the given morph subject
     * (Lead, Customer, Contact, Opportunity, Quotation, Activity).
     *
     * @param  Model  $docable  subject the document is attached to
     * @param  UploadedFile  $file  raw upload from the HTTP layer
     * @param  User  $actor  user performing the upload (becomes uploaded_by)
     *
     * @throws InvalidArgumentException when extension / MIME / size fail
     */
    public function upload(Model $docable, UploadedFile $file, User $actor): Document
    {
        $this->assertSubject($docable);

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        $this->assertExtension($extension);
        $this->assertMimeMatchesExtension($extension, $mime);
        $this->assertSize($size);

        $disk = (string) config('filesystems.docs_disk', 'docs');
        $path = $file->storeAs(
            $this->prefixFor($docable),
            $this->buildFilename($file, $extension),
            ['disk' => $disk],
        );

        // storeAs() returns false when the disk driver rejects the write.
        // In practice the disk is a local private disk so this would only
        // surface on a permissions issue; we treat it as a hard failure.
        if ($path === false) {
            throw new InvalidArgumentException(
                'No se pudo almacenar el archivo en el disco privado de documentos.'
            );
        }

        $document = new Document([
            'docable_type' => $docable->getMorphClass(),
            'docable_id' => (int) $docable->getKey(),
            'name' => (string) $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => (string) $path,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => $size,
            'uploaded_by' => (int) $actor->id,
            'uploaded_at' => now(),
        ]);

        $document->save();

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->event('document-uploaded')
            ->withProperties([
                'subject_type' => $docable->getMorphClass(),
                'subject_id' => (int) $docable->getKey(),
                'name' => $document->name,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'extension' => $document->extension,
            ])
            ->log("Documento \"{$document->name}\" subido");

        return $document;
    }

    /**
     * Authorize the download and return a streamed response (ADR-011).
     * Always go through this service: the route must never build a public
     * URL to a documents/ file.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function download(Document $document, User $actor): StreamedResponse
    {
        $this->assertDownloadAuthorized($document, $actor);

        $disk = Storage::disk($document->disk ?: (string) config('filesystems.docs_disk', 'docs'));

        $ip = request()?->ip();

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->event('document-downloaded')
            ->withProperties([
                'subject_type' => $document->docable_type,
                'subject_id' => $document->docable_id,
                'name' => $document->name,
                'ip' => $ip,
            ])
            ->log("Documento \"{$document->name}\" descargado");

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        return $disk->download($document->path, $document->name);
    }

    /**
     * Hard-delete: physical file removed + DB row gone (RF-DOC-005, ADR-011).
     *
     * The other modules in this app use soft-delete because records carry
     * history. Documents are physical artifacts — when the user removes
     * them, both the metadata and the file must disappear so the storage
     * does not leak. Only the original uploader or an admin (RBAC) may
     * delete a document.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(Document $document, User $actor): void
    {
        if (! $this->canDelete($document, $actor)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'No tiene permisos para eliminar este documento.'
            );
        }

        $disk = Storage::disk($document->disk ?: (string) config('filesystems.docs_disk', 'docs'));

        if ($document->path !== null && $disk->exists($document->path)) {
            $disk->delete($document->path);
        }

        // Hard delete: physical file + DB row gone. Document uses SoftDeletes
        // for accidental deletes during business operations, but the
        // documents module treats delete() as the operator's intentional
        // removal — same as the file disappearance on disk.
        $document->forceDelete();

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->event('document-deleted')
            ->withProperties([
                'subject_type' => $document->docable_type,
                'subject_id' => $document->docable_id,
                'name' => $document->name,
            ])
            ->log("Documento \"{$document->name}\" eliminado");
    }

    /**
     * All documents attached to the given subject, newest first.
     *
     * @return Collection<int, Document>
     */
    public function forSubject(Model $docable): Collection
    {
        $this->assertSubject($docable);

        return Document::query()
            ->where('docable_type', $docable->getMorphClass())
            ->where('docable_id', (int) $docable->getKey())
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Whether the given actor is allowed to download the document.
     *
     * Authorization layers (any passing check grants access):
     *   1. documents.view.any permission (admin scope).
     *   2. The subject's owner is inside DataScopeService::visibleOwnerIds
     *      AND the user has documents.download permission.
     */
    public function canDownload(Document $document, User $actor): bool
    {
        if (! $actor->can('documents.download')) {
            return false;
        }

        if ($actor->can('documents.view.any')) {
            return true;
        }

        $subject = $document->docable;

        if ($subject === null) {
            return false;
        }

        $supportTicket = match (true) {
            $subject instanceof \App\Models\SupportTicket => $subject,
            $subject instanceof \App\Models\SupportTicketUpdate => $subject->ticket,
            $subject instanceof \App\Models\SupportObservation => $subject->ticket,
            $subject instanceof \App\Models\SupportIncidentDetail => $subject->ticket,
            $subject instanceof \App\Models\SupportSessionDetail => $subject->ticket,
            default => null,
        };

        if ($supportTicket instanceof \App\Models\SupportTicket) {
            return app(\App\Services\SupportTicketScopeService::class)->canView($actor, $supportTicket);
        }

        $ownerId = $subject->owner_id
            ?? $subject->customer?->owner_id
            ?? null;

        if ($ownerId === null) {
            return false;
        }

        $visible = $this->dataScope->visibleOwnerIds($actor);

        return $visible === null || in_array((int) $ownerId, $visible, true);
    }

    /**
     * Whether the actor can hard-delete the document.
     *
     * Rule: original uploader OR admin role (documents.view.any).
     */
    public function canDelete(Document $document, User $actor): bool
    {
        if ((int) $document->uploaded_by === (int) $actor->id) {
            return $actor->can('documents.delete');
        }

        return $actor->can('documents.view.any');
    }

    /**
     * Centralized authorization entry point used by `download()`.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    private function assertDownloadAuthorized(Document $document, User $actor): void
    {
        if (! $this->canDownload($document, $actor)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'No tiene permisos para descargar este documento.'
            );
        }
    }

    /**
     * Reject subjects that the documents module does not support.
     */
    private function assertSubject(Model $docable): void
    {
        $allowed = [
            \App\Models\Lead::class,
            \App\Models\Customer::class,
            \App\Models\Contact::class,
            \App\Models\Opportunity::class,
            \App\Models\Quotation::class,
            \App\Models\Activity::class,
            \App\Models\SupportTicket::class,
            \App\Models\SupportTicketUpdate::class,
            \App\Models\SupportObservation::class,
            \App\Models\SupportIncidentDetail::class,
            \App\Models\SupportSessionDetail::class,
        ];

        if (! in_array($docable::class, $allowed, true)) {
            throw new InvalidArgumentException(
                "Tipo de sujeto no soportado para documentos: {$docable->getMorphClass()}."
            );
        }
    }

    /**
     * Whitelist the extension. The mimes rule on the FormRequest also
     * enforces this, but the service is the second line of defence.
     */
    private function assertExtension(string $extension): void
    {
        if ($extension === '' || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                "Extensión no permitida: \"{$extension}\". Solo se aceptan: ".
                implode(', ', self::ALLOWED_EXTENSIONS).'.'
            );
        }
    }

    /**
     * Cross-check that the MIME the browser reported is consistent with
     * the extension (RNF-SEG-002). A naive attacker can rename .exe to
     * .pdf; this catches that.
     */
    private function assertMimeMatchesExtension(string $extension, string $mime): void
    {
        $map = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'txt' => ['text/plain'],
        ];

        $expected = $map[$extension] ?? [];

        if ($expected === []) {
            return;
        }

        if (! in_array($mime, $expected, true)) {
            throw new InvalidArgumentException(
                "El tipo MIME \"{$mime}\" no coincide con la extensión .{$extension}."
            );
        }
    }

    /**
     * Honor the configurable cap. Setting: `documents.max_size` (integer).
     * Hard fallback: 10 MB (documented in ADR-011).
     */
    private function assertSize(int $size): void
    {
        $max = (int) \App\Models\Setting::query()
            ->where('key', 'documents.max_size')
            ->value('value');

        if ($max <= 0) {
            $max = self::DEFAULT_MAX_SIZE_BYTES;
        }

        if ($size <= 0 || $size > $max) {
            throw new InvalidArgumentException(
                "El archivo excede el tamaño máximo permitido ({$max} bytes)."
            );
        }
    }

    /**
     * Storage prefix per subject type — keeps the disk tree tidy.
     */
    private function prefixFor(Model $docable): string
    {
        $class = $docable::class;
        $short = match ($class) {
            \App\Models\Lead::class => 'leads',
            \App\Models\Customer::class => 'customers',
            \App\Models\Contact::class => 'contacts',
            \App\Models\Opportunity::class => 'opportunities',
            \App\Models\Quotation::class => 'quotations',
            \App\Models\Activity::class => 'activities',
            \App\Models\SupportTicket::class => 'support/tickets',
            \App\Models\SupportTicketUpdate::class => 'support/updates',
            \App\Models\SupportObservation::class => 'support/observations',
            \App\Models\SupportIncidentDetail::class => 'support/incidents',
            \App\Models\SupportSessionDetail::class => 'support/sessions',
            default => 'misc',
        };

        return $short.'/'.$docable->getKey();
    }

    /**
     * Build a unique filename: original sanitized + microtime suffix.
     */
    private function buildFilename(UploadedFile $file, string $extension): string
    {
        $base = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $base) ?: 'documento';
        $safe = trim($safe, '_-');

        if ($safe === '') {
            $safe = 'documento';
        }

        return $safe.'_'.now()->format('YmdHis').'.'.$extension;
    }
}