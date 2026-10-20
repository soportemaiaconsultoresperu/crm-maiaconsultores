<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\PrivateFileResponder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A4 — the single decision behind private-file delivery.
 *
 * Proves the redirect branch with a real S3 adapter: the URL is signed
 * locally by the AWS SDK, so no network is touched and no real credentials
 * are needed. Proves the stream branch with a local disk, including
 * Laravel's faked disk, which advertises temporary URLs and must still
 * stream. Proves that a disk which cannot sign, and a disk which claims it
 * can but throws, both fall back to streaming.
 */
class PrivateFileResponderTest extends TestCase
{
    public function test_a_cloud_disk_yields_a_redirect_to_a_real_signed_url_bounded_by_configuration(): void
    {
        config(['filesystems.temporary_url_ttl' => 120]);
        Storage::set('docs', $this->s3Disk());

        $redirect = app(PrivateFileResponder::class)->signedUrlRedirect('docs', 'private/docs/cert.pdf');

        $this->assertInstanceOf(RedirectResponse::class, $redirect);

        $url = $redirect->getTargetUrl();
        $this->assertStringContainsString('crm-files.s3.amazonaws.com', $url);
        $this->assertStringContainsString('private/docs/cert.pdf', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);

        // The expiry is bounded by the configured lifetime.
        $this->assertSame(1, preg_match('/X-Amz-Expires=(\d+)/', $url, $matches));
        $this->assertGreaterThan(0, (int) $matches[1]);
        $this->assertLessThanOrEqual(120, (int) $matches[1]);
    }

    public function test_a_signed_cloud_url_carries_the_intended_download_name_and_content_type(): void
    {
        Storage::set('docs', $this->s3Disk());

        $redirect = app(PrivateFileResponder::class)->signedUrlRedirect(
            'docs',
            'private/docs/01J8Z9-key.pdf',
            'cotizacion.pdf',
            'application/pdf',
        );

        $this->assertInstanceOf(RedirectResponse::class, $redirect);

        // Assert on the query parameters the browser actually sends to S3,
        // not on the raw (percent-encoded) URL string.
        $query = $this->queryParameters($redirect->getTargetUrl());

        $this->assertSame('attachment; filename="cotizacion.pdf"', $query['response-content-disposition'] ?? null);
        $this->assertSame('application/pdf', $query['response-content-type'] ?? null);
    }

    public function test_an_inline_signed_url_carries_an_inline_disposition(): void
    {
        Storage::set('docs', $this->s3Disk());

        $redirect = app(PrivateFileResponder::class)->signedUrlRedirect(
            'docs',
            'private/docs/certificate.pdf',
            'certificate.pdf',
            'application/pdf',
            PrivateFileResponder::DISPOSITION_INLINE,
        );

        $this->assertInstanceOf(RedirectResponse::class, $redirect);

        $query = $this->queryParameters($redirect->getTargetUrl());

        $this->assertSame('inline; filename="certificate.pdf"', $query['response-content-disposition'] ?? null);
        $this->assertSame('application/pdf', $query['response-content-type'] ?? null);
    }

    public function test_a_local_disk_is_never_redirected_even_when_it_advertises_temporary_urls(): void
    {
        $fake = Storage::fake('docs');

        // Laravel's fake installs a temporary-URL callback, so the naive
        // `providesTemporaryUrls()` check would redirect; the strategy must
        // still stream a local disk.
        $this->assertTrue($fake->providesTemporaryUrls());

        $this->assertNull(app(PrivateFileResponder::class)->signedUrlRedirect('docs', 'private/docs/cert.pdf'));
    }

    public function test_a_remote_disk_that_cannot_sign_is_not_redirected(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('providesTemporaryUrls')->andReturn(false);
        Storage::set('docs', $disk);

        $this->assertNull(app(PrivateFileResponder::class)->signedUrlRedirect(
            'docs',
            'private/docs/cert.pdf',
            'cotizacion.pdf',
            'application/pdf',
        ));
    }

    public function test_a_disk_that_throws_while_signing_is_not_redirected_and_the_outage_is_logged(): void
    {
        Log::spy();

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('providesTemporaryUrls')->andReturn(true);
        $disk->shouldReceive('temporaryUrl')->andThrow(new RuntimeException('signing is broken'));
        Storage::set('docs', $disk);

        $this->assertNull(app(PrivateFileResponder::class)->signedUrlRedirect(
            'docs',
            'private/docs/cert.pdf',
            'cotizacion.pdf',
            'application/pdf',
        ));

        // The fallback is silent for the user but must be visible to an
        // operator, otherwise a stale credential looks like healthy storage.
        Log::shouldHaveReceived('error')->once();
    }

    public function test_the_expiry_falls_back_to_the_bounded_default_when_configuration_is_absent(): void
    {
        config(['filesystems.temporary_url_ttl' => null]);

        $this->assertSame(
            PrivateFileResponder::DEFAULT_TTL_SECONDS,
            app(PrivateFileResponder::class)->ttlSeconds(),
        );
    }

    /**
     * Decode the signed URL query string into its parameters.
     *
     * @return array<string, string>
     */
    private function queryParameters(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function s3Disk(): FilesystemAdapter
    {
        return Storage::createS3Driver([
            'driver' => 's3',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'secret',
            'region' => 'us-east-1',
            'bucket' => 'crm-files',
            'root' => 'private/docs',
            'throw' => false,
        ]);
    }
}
