<?php

declare(strict_types=1);

namespace App\Services\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A4 — authorized delivery of a private file.
 *
 * The single place that decides how a private file reaches the browser:
 *
 *   - when the backing disk is a remote disk that can sign a URL, the
 *     application redirects to a short-lived signed URL and the bytes never
 *     pass through the application server;
 *   - otherwise (a local disk, or a test fake) the caller streams the bytes
 *     exactly as it did before.
 *
 * This class never decides authorization. Callers must run their own
 * authorization check first and only then ask for the redirect, so a signed
 * URL can never be produced for a file the current user may not read.
 *
 * A local disk is deliberately excluded even when it advertises temporary
 * URLs (Laravel's `serve` option signs a local route, and `Storage::fake`
 * installs a temporary-URL callback): those URLs still come back through the
 * application, so streaming stays the behaviour.
 *
 * A signing failure is NOT an authorization decision and NOT a download
 * failure: when a remote disk claims it can sign but throws, this class logs
 * the outage and returns null so the caller streams instead. The distinction
 * between "this disk cannot sign" and "this disk claimed it could but failed"
 * only changes the logging, never the fallback.
 *
 * The download identity travels through this same strategy. Objects are
 * stored without content metadata, so without an explicit response override a
 * cloud download would come back named after the generated object key and
 * typed with the provider default. The signed URL therefore carries the
 * intended `Content-Disposition` and content type as GET-object response
 * overrides, which is what makes a cloud download indistinguishable from a
 * local stream.
 */
final class PrivateFileResponder
{
    public const DEFAULT_TTL_SECONDS = 300;

    public const DISPOSITION_ATTACHMENT = 'attachment';

    public const DISPOSITION_INLINE = 'inline';

    /**
     * The redirect for a private file when the disk can sign a URL, or null
     * when the caller must stream the file itself.
     *
     * `$downloadName` and `$contentType` are the identity the browser must
     * observe; when either is null the corresponding override is omitted and
     * the provider default applies. `$disposition` mirrors what the streaming
     * branch would send: `attachment` for a download, `inline` for a preview
     * the browser renders in place.
     */
    public function signedUrlRedirect(
        string $disk,
        string $path,
        ?string $downloadName = null,
        ?string $contentType = null,
        string $disposition = self::DISPOSITION_ATTACHMENT,
    ): ?RedirectResponse {
        $filesystem = Storage::disk($disk);

        if (! $this->supportsSignedUrls($filesystem)) {
            return null;
        }

        /** @var FilesystemAdapter $filesystem */
        try {
            $url = $filesystem->temporaryUrl(
                $path,
                $this->expiration(),
                $this->responseOverrides($downloadName, $contentType, $disposition),
            );
        } catch (Throwable $exception) {
            // The disk advertised temporary URLs but could not produce one.
            // Streaming still works, so degrade to it instead of a 500, and
            // leave an operator-visible trace: a signing outage is usually a
            // credentials or endpoint misconfiguration, not a client error.
            Log::error('No se pudo firmar la URL temporal; se transmite el archivo por la aplicación.', [
                'disk' => $disk,
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return redirect()->away($url);
    }

    public function supportsSignedUrls(Filesystem $filesystem): bool
    {
        return $filesystem instanceof FilesystemAdapter
            && ! $filesystem instanceof LocalFilesystemAdapter
            && $filesystem->providesTemporaryUrls();
    }

    public function expiration(): DateTimeInterface
    {
        return now()->addSeconds($this->ttlSeconds());
    }

    /**
     * Lifetime of the issued URL, in seconds, bounded by configuration.
     */
    public function ttlSeconds(): int
    {
        $ttl = config('filesystems.temporary_url_ttl');

        return is_numeric($ttl) ? max(1, (int) $ttl) : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Object-level overrides the provider bakes into the signed URL. The S3
     * presigner maps `ResponseContentDisposition` to
     * `response-content-disposition` and `ResponseContentType` to
     * `response-content-type`, which the provider applies to the GET response
     * regardless of what metadata the stored object carries.
     *
     * @return array<string, string>
     */
    private function responseOverrides(?string $downloadName, ?string $contentType, string $disposition): array
    {
        $overrides = [];

        if ($downloadName !== null && $downloadName !== '') {
            $overrides['ResponseContentDisposition'] = $this->contentDisposition($downloadName, $disposition);
        }

        if ($contentType !== null && $contentType !== '') {
            $overrides['ResponseContentType'] = $contentType;
        }

        return $overrides;
    }

    /**
     * Quote and escape the stored name so a stray quote, backslash or control
     * character cannot break the header the browser will parse.
     */
    private function contentDisposition(string $downloadName, string $disposition): string
    {
        $safe = str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', ''], $downloadName);

        return $disposition.'; filename="'.$safe.'"';
    }
}
