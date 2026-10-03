<?php

namespace App\Services\Content;

use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentItem;
use App\Services\Storage\PrivateFileResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single source of truth for a content item's media attachments (T7).
 *
 * Every rule lives here so the HTTP layer, the views and the tests never
 * re-derive it:
 *
 *   - which extensions are accepted per kind (JPG/PNG/WEBP, MP4/MOV);
 *   - the size ceiling per kind (10 MB image, 200 MB video by default);
 *   - the per-content ceiling (10 attachments, links included);
 *   - the fact that an upload is stored on a PRIVATE disk and only ever
 *     downloaded through the authorized route;
 *   - the fact that a link counts toward the per-content ceiling but has no
 *     size and consumes no upload capacity.
 *
 * An external link accepts any absolute http or https URL. When the URL path
 * clearly names an accepted image or video file the link keeps that media kind
 * so the interface can label it; otherwise it is stored as a generic link
 * (ContentAttachment::KIND_LINK) that the interface shows as a link opening
 * outside the CRM. Only genuinely invalid links — a non-http scheme, a
 * malformed or empty value — are refused.
 *
 * The real content is sniffed from the file's first bytes, never trusted from
 * the extension or the client-declared MIME type: a text file renamed to
 * `foto.jpg` is rejected because its bytes match no accepted media signature.
 * The stored MIME type and extension are the ones detected, not the claimed
 * ones.
 *
 * There is deliberately no automatic purge path. Retiring an attachment only
 * soft-deletes its row and trashing a content only soft-deletes its content
 * row: the physical file stays on disk in both cases so the content can
 * recover it. Physical files are removed in exactly two explicit, transactional
 * cases: `discardStoredFile()` cleans up a file this service copied for a
 * duplication that then rolled back, so a failed duplication leaves no orphan,
 * and the operator purge (`ContentTrashService::purge()`) empties the trash.
 * Nothing removes a file on a schedule, a console command or a model event.
 *
 * The size ceilings are read from `config('content.attachments.*')` so a test
 * can prove the boundary with a tiny controlled override while production keeps
 * the agreed 10 MB / 200 MB defaults.
 */
class ContentAttachmentService
{
    /**
     * Existing private disk (config/filesystems.php, ADR-011). Its root is
     * `storage/app/private/docs`, its visibility is private, it declares no
     * public `url` and it is not served by any route.
     */
    public const DISK = 'docs';

    public const DEFAULT_IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    public const DEFAULT_VIDEO_MAX_BYTES = 200 * 1024 * 1024;

    public const DEFAULT_MAX_PER_CONTENT = 10;

    /**
     * Accepted extension => [kind, canonical stored extension, MIME type].
     * `jpg` and `jpeg` are the same format; the stored file always uses the
     * canonical `jpg`.
     *
     * @var array<string, array{kind: string, extension: string, mime: string}>
     */
    private const FORMATS = [
        'jpg' => ['kind' => ContentAttachment::KIND_IMAGE, 'extension' => 'jpg', 'mime' => 'image/jpeg'],
        'jpeg' => ['kind' => ContentAttachment::KIND_IMAGE, 'extension' => 'jpg', 'mime' => 'image/jpeg'],
        'png' => ['kind' => ContentAttachment::KIND_IMAGE, 'extension' => 'png', 'mime' => 'image/png'],
        'webp' => ['kind' => ContentAttachment::KIND_IMAGE, 'extension' => 'webp', 'mime' => 'image/webp'],
        'mp4' => ['kind' => ContentAttachment::KIND_VIDEO, 'extension' => 'mp4', 'mime' => 'video/mp4'],
        'mov' => ['kind' => ContentAttachment::KIND_VIDEO, 'extension' => 'mov', 'mime' => 'video/quicktime'],
    ];

    public function __construct(
        private readonly ?PrivateFileResponder $responder = null,
    ) {
    }

    public function imageMaxBytes(): int
    {
        return (int) (config('content.attachments.image_max_bytes') ?: self::DEFAULT_IMAGE_MAX_BYTES);
    }

    public function videoMaxBytes(): int
    {
        return (int) (config('content.attachments.video_max_bytes') ?: self::DEFAULT_VIDEO_MAX_BYTES);
    }

    public function maxPerContent(): int
    {
        return (int) (config('content.attachments.max_per_content') ?: self::DEFAULT_MAX_PER_CONTENT);
    }

    public function maxBytesFor(string $kind): int
    {
        return $kind === ContentAttachment::KIND_VIDEO
            ? $this->videoMaxBytes()
            : $this->imageMaxBytes();
    }

    /**
     * The limits and accepted formats the screens show, in one place so the
     * Spanish copy can never drift from what the service enforces.
     *
     * @return array{image_max_bytes: int, video_max_bytes: int, max_per_content: int, image_extensions: list<string>, video_extensions: list<string>}
     */
    public function limits(): array
    {
        return [
            'image_max_bytes' => $this->imageMaxBytes(),
            'video_max_bytes' => $this->videoMaxBytes(),
            'max_per_content' => $this->maxPerContent(),
            'image_extensions' => ['JPG', 'PNG', 'WEBP'],
            'video_extensions' => ['MP4', 'MOV'],
        ];
    }

    /**
     * Store an uploaded image or video on the private disk and attach it to the
     * content item.
     *
     * @throws ValidationException on unsupported extension, spoofed content,
     *                             oversize file or a full content
     */
    public function upload(ContentItem $item, UploadedFile $file): ContentAttachment
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $declared = $this->formatForExtension($extension);

        if ($declared === null) {
            throw ValidationException::withMessages([
                'file' => 'El formato del archivo no está permitido. Se aceptan imágenes JPG, PNG o WEBP y videos MP4 o MOV.',
            ]);
        }

        $detected = $this->detectFormat($file->getRealPath());

        if ($detected === null || $detected['kind'] !== $declared['kind']) {
            throw ValidationException::withMessages([
                'file' => 'El contenido real del archivo no corresponde a una imagen o video permitido. No se confía en el nombre ni en el tipo declarado.',
            ]);
        }

        $size = (int) $file->getSize();

        if ($size > $this->maxBytesFor($detected['kind'])) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'El archivo supera el límite de %s MB permitido para %s.',
                    $this->humanMegabytes($this->maxBytesFor($detected['kind'])),
                    $detected['kind'] === ContentAttachment::KIND_VIDEO ? 'videos' : 'imágenes',
                ),
            ]);
        }

        $this->assertCapacity($item, 'file');

        $path = $file->storeAs(
            $this->directoryFor($item),
            Str::uuid()->toString().'.'.$detected['extension'],
            ['disk' => self::DISK],
        );

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo almacenar el archivo en el disco privado.',
            ]);
        }

        return ContentAttachment::query()->create([
            'content_item_id' => $item->getKey(),
            'kind' => $detected['kind'],
            'path' => (string) $path,
            'url' => null,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $detected['mime'],
            'size_bytes' => $size,
            'sort' => $this->nextSort($item),
        ]);
    }

    /**
     * Attach an external link to a content. Any absolute http or https URL is
     * accepted: when its path clearly names an accepted image or video file the
     * link is classified as that media kind so the interface can label it,
     * otherwise it is stored as a generic link. Either way it still counts
     * toward the per-content ceiling, even though it has no size and consumes
     * no upload capacity.
     *
     * @throws ValidationException when the URL is empty, not an absolute
     *                             http/https URL or the content is full
     */
    public function attachLink(ContentItem $item, string $url): ContentAttachment
    {
        if (! $this->isAcceptableLink($url)) {
            throw ValidationException::withMessages([
                'url' => 'El enlace no es válido. Indique una dirección completa que empiece con http:// o https://.',
            ]);
        }

        $urlPath = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $format = $this->formatForExtension($extension);

        $this->assertCapacity($item, 'url');

        return ContentAttachment::query()->create([
            'content_item_id' => $item->getKey(),
            'kind' => $format['kind'] ?? ContentAttachment::KIND_LINK,
            'path' => null,
            'url' => $url,
            'original_name' => $format !== null && basename($urlPath) !== '' ? basename($urlPath) : null,
            'mime_type' => $format['mime'] ?? null,
            'size_bytes' => null,
            'sort' => $this->nextSort($item),
        ]);
    }

    /**
     * Persist a new manual position. There is no reorder cap and no file access:
     * this is metadata only.
     */
    public function reorder(ContentAttachment $attachment, int $sort): ContentAttachment
    {
        $attachment->forceFill(['sort' => $sort])->save();

        return $attachment;
    }

    /**
     * Authorized download of an uploaded file. The controller has already
     * resolved reachability and the project `view` ability; this only streams
     * the private file, so no public URL is ever produced.
     *
     * @throws NotFoundHttpException when the row is a link or the file is gone
     */
    public function download(ContentAttachment $attachment): StreamedResponse|RedirectResponse
    {
        if (! $attachment->isUpload()) {
            throw new NotFoundHttpException('El adjunto es un enlace, no un archivo descargable.');
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);
        $path = (string) $attachment->path;

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException('El archivo adjunto no está disponible.');
        }

        // The controller authorized the request before calling this method, so
        // a signed URL is only ever issued for a file this user may read.
        $redirect = $this->responder()->signedUrlRedirect(self::DISK, $path);

        if ($redirect !== null) {
            return $redirect;
        }

        return $disk->download($path, $attachment->original_name ?: basename($path));
    }

    /**
     * The shared delivery strategy, resolved lazily so `new
     * ContentAttachmentService` keeps working without the container.
     */
    private function responder(): PrivateFileResponder
    {
        return $this->responder ?? app(PrivateFileResponder::class);
    }

    /**
     * Copy one attachment onto another content, giving the copy its OWN stored
     * file so removing one never affects the other (T8a duplication).
     *
     * An upload's bytes are copied to a fresh generated path inside the target
     * content's own directory and a new row points at that path; a link is
     * copied row-only, because it owns no local file. The source row and file
     * are never modified.
     *
     * @throws ValidationException when the source file is missing or cannot be
     *                             copied
     */
    public function copyOnto(ContentItem $target, ContentAttachment $source): ContentAttachment
    {
        if (! $source->isUpload()) {
            return ContentAttachment::query()->create([
                'content_item_id' => $target->getKey(),
                'kind' => $source->kind,
                'path' => null,
                'url' => $source->url,
                'original_name' => $source->original_name,
                'mime_type' => $source->mime_type,
                'size_bytes' => null,
                'sort' => $this->nextSort($target),
            ]);
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);
        $from = (string) $source->path;

        if (! $disk->exists($from)) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo duplicar el adjunto "'.($source->original_name ?: 'archivo').'": el archivo ya no está disponible.',
            ]);
        }

        $extension = pathinfo($from, PATHINFO_EXTENSION);
        $to = $this->directoryFor($target).'/'.Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        if (! $disk->copy($from, $to)) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo copiar el archivo adjunto al duplicar el contenido.',
            ]);
        }

        return ContentAttachment::query()->create([
            'content_item_id' => $target->getKey(),
            'kind' => $source->kind,
            'path' => $to,
            'url' => null,
            'original_name' => $source->original_name,
            'mime_type' => $source->mime_type,
            'size_bytes' => $source->size_bytes,
            'sort' => $this->nextSort($target),
        ]);
    }

    /**
     * Delete a stored file from the private disk. This is the module's single
     * low-level file-removal primitive, called only from the two explicit,
     * transactional paths: the rollback cleanup that removes a copy this
     * service made for a duplication that then failed, and the operator purge
     * that empties the trash. A committed attachment keeps its file.
     */
    public function discardStoredFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Refuse an attachment once the content already holds the ceiling. Counts
     * active rows only, links included: retiring an attachment frees a slot
     * while keeping the row and the file.
     *
     * @throws ValidationException
     */
    private function assertCapacity(ContentItem $item, string $field): void
    {
        $current = ContentAttachment::query()
            ->where('content_item_id', $item->getKey())
            ->count();

        if ($current >= $this->maxPerContent()) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'El contenido ya alcanzó el máximo de %d archivos adjuntos por contenido. Los enlaces también cuentan.',
                    $this->maxPerContent(),
                ),
            ]);
        }
    }

    /**
     * An attachment link must be an absolute http or https URL. Everything else
     * — a non-http scheme, a malformed value or an empty string — is refused.
     * This mirrors the `url:http,https` request rule so the HTTP layer and the
     * service never disagree about what a valid link is.
     */
    private function isAcceptableLink(string $url): bool
    {
        return trim($url) !== '' && Str::isUrl($url, ['http', 'https']);
    }

    /**
     * @return array{kind: string, extension: string, mime: string}|null
     */
    private function formatForExtension(string $extension): ?array
    {
        return self::FORMATS[$extension] ?? null;
    }

    /**
     * Sniff the real media format from the file's leading bytes. Returns null
     * for anything that is not a JPG, PNG, WEBP, MP4 or MOV, regardless of the
     * name or the declared MIME type.
     *
     * @return array{kind: string, extension: string, mime: string}|null
     */
    private function detectFormat(string $path): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 32);
        fclose($handle);

        // JPEG: FF D8 FF
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return self::FORMATS['jpg'];
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return self::FORMATS['png'];
        }

        // WEBP: "RIFF" .... "WEBP"
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return self::FORMATS['webp'];
        }

        // ISO base media (MP4/MOV): box size + "ftyp" + brand.
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return str_starts_with(substr($head, 8, 4), 'qt')
                ? self::FORMATS['mov']
                : self::FORMATS['mp4'];
        }

        // Some MP4/MOV files open directly with a `moov` atom.
        if (strlen($head) >= 8 && substr($head, 4, 4) === 'moov') {
            return self::FORMATS['mp4'];
        }

        return null;
    }

    private function directoryFor(ContentItem $item): string
    {
        return sprintf('content-attachments/%d/%d', (int) $item->content_project_id, (int) $item->getKey());
    }

    private function nextSort(ContentItem $item): int
    {
        $max = ContentAttachment::query()
            ->where('content_item_id', $item->getKey())
            ->reorder()
            ->max('sort');

        return (int) $max + 1;
    }

    private function humanMegabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    }
}
