<?php

declare(strict_types=1);

namespace App\Services\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

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
 */
final class PrivateFileResponder
{
    public const DEFAULT_TTL_SECONDS = 300;

    /**
     * The redirect for a private file when the disk can sign a URL, or null
     * when the caller must stream the file itself.
     */
    public function signedUrlRedirect(string $disk, string $path): ?RedirectResponse
    {
        $filesystem = Storage::disk($disk);

        if (! $this->supportsSignedUrls($filesystem)) {
            return null;
        }

        /** @var FilesystemAdapter $filesystem */
        return redirect()->away($filesystem->temporaryUrl($path, $this->expiration()));
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
}
