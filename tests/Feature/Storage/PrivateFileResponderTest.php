<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\PrivateFileResponder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A4 — the single decision behind private-file delivery.
 *
 * Proves the redirect branch with a real S3 adapter: the URL is signed
 * locally by the AWS SDK, so no network is touched and no real credentials
 * are needed. Proves the stream branch with a local disk, including
 * Laravel's faked disk, which advertises temporary URLs and must still
 * stream.
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

    public function test_a_local_disk_is_never_redirected_even_when_it_advertises_temporary_urls(): void
    {
        $fake = Storage::fake('docs');

        // Laravel's fake installs a temporary-URL callback, so the naive
        // `providesTemporaryUrls()` check would redirect; the strategy must
        // still stream a local disk.
        $this->assertTrue($fake->providesTemporaryUrls());

        $this->assertNull(app(PrivateFileResponder::class)->signedUrlRedirect('docs', 'private/docs/cert.pdf'));
    }

    public function test_the_expiry_falls_back_to_the_bounded_default_when_configuration_is_absent(): void
    {
        config(['filesystems.temporary_url_ttl' => null]);

        $this->assertSame(
            PrivateFileResponder::DEFAULT_TTL_SECONDS,
            app(PrivateFileResponder::class)->ttlSeconds(),
        );
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
