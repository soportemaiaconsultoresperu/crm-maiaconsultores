<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * A2 — the effective storage configuration in both modes.
 *
 * Booting the application with the three AWS credentials present and with
 * them absent proves that the `local`, `docs` and `public` disks resolve to
 * S3 or to today's local definitions, and that a relative stored path keeps
 * the same meaning (`private`, `private/docs`, `public`) in both modes.
 *
 * No real AWS call is made and no network is touched: only the resolved
 * configuration is observed, so this holds without real credentials.
 */
class StorageModeConfigurationTest extends TestCase
{
    /**
     * The environment keys this test controls, so they can be restored.
     */
    private const AWS_KEYS = [
        'AWS_BUCKET',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'AWS_URL',
        'AWS_ENDPOINT',
        'AWS_USE_PATH_STYLE_ENDPOINT',
    ];

    /**
     * @var array<string, array{env: string|null, server: string|null, getenv: string|false}>
     */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        // Leave the shared Dotenv repository as it was found so later test
        // classes reload `.env` normally.
        Env::enablePutenv();

        parent::tearDown();
    }

    public function test_cloud_mode_resolves_the_three_semantic_disks_to_s3_with_local_mirroring_prefixes(): void
    {
        $this->bootWith([
            'AWS_BUCKET' => 'crm-files',
            'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE',
            'AWS_SECRET_ACCESS_KEY' => 'secret',
            'AWS_DEFAULT_REGION' => 'us-east-2',
            'AWS_URL' => 'https://cdn.example.com',
        ]);

        $disks = config('filesystems.disks');

        // The private disks keep private visibility and expose no public URL.
        $this->assertSame('s3', $disks['local']['driver']);
        $this->assertSame('private', $disks['local']['root']);
        $this->assertSame('private', $disks['local']['visibility']);
        $this->assertArrayNotHasKey('url', $disks['local']);
        $this->assertArrayNotHasKey('serve', $disks['local']);

        $this->assertSame('s3', $disks['docs']['driver']);
        $this->assertSame('private/docs', $disks['docs']['root']);
        $this->assertSame('private', $disks['docs']['visibility']);
        $this->assertArrayNotHasKey('url', $disks['docs']);
        $this->assertArrayNotHasKey('serve', $disks['docs']);

        // The public disk keeps public visibility and the configured URL.
        $this->assertSame('s3', $disks['public']['driver']);
        $this->assertSame('public', $disks['public']['root']);
        $this->assertSame('public', $disks['public']['visibility']);
        $this->assertSame('https://cdn.example.com', $disks['public']['url']);

        // One S3 connection: the standalone `s3` disk and the three semantic
        // disks share the same credentials, bucket and region.
        foreach (['local', 'docs', 'public', 's3'] as $name) {
            $this->assertSame('AKIAEXAMPLE', $disks[$name]['key']);
            $this->assertSame('secret', $disks[$name]['secret']);
            $this->assertSame('crm-files', $disks[$name]['bucket']);
            $this->assertSame('us-east-2', $disks[$name]['region']);
        }
    }

    public function test_cloud_mode_public_url_falls_back_to_the_standard_bucket_url_shape(): void
    {
        $this->bootWith([
            'AWS_BUCKET' => 'crm-files',
            'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE',
            'AWS_SECRET_ACCESS_KEY' => 'secret',
            'AWS_DEFAULT_REGION' => 'eu-west-1',
        ]);

        $this->assertSame(
            'https://crm-files.s3.eu-west-1.amazonaws.com',
            config('filesystems.disks.public.url'),
        );
    }

    public function test_local_mode_keeps_todays_definitions_for_all_three_disks(): void
    {
        $this->bootWith([]);

        $disks = config('filesystems.disks');

        $this->assertSame([
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ], $disks['local']);

        $this->assertSame([
            'driver' => 'local',
            'root' => storage_path('app/private/docs'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ], $disks['docs']);

        $this->assertSame([
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ], $disks['public']);
    }

    public function test_cloud_mode_makes_the_public_disk_s3_backed_with_public_visibility_and_a_direct_url(): void
    {
        $this->bootWith([
            'AWS_BUCKET' => 'crm-files',
            'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE',
            'AWS_SECRET_ACCESS_KEY' => 'secret',
            'AWS_DEFAULT_REGION' => 'us-east-2',
            'AWS_URL' => 'https://cdn.example.com',
        ]);

        $public = config('filesystems.disks.public');

        $this->assertSame('s3', $public['driver']);
        $this->assertSame('public', $public['root']);
        $this->assertSame('public', $public['visibility']);
        $this->assertSame('https://cdn.example.com', $public['url']);
        $this->assertArrayNotHasKey('serve', $public);
    }

    public function test_local_mode_keeps_todays_public_disk_definition(): void
    {
        $this->bootWith([]);

        $this->assertSame([
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ], config('filesystems.disks.public'));
    }

    /**
     * Set the AWS environment variables, then boot a fresh application that
     * reads them while loading its configuration.
     *
     * @param  array<string, string>  $environment
     */
    private function bootWith(array $environment): void
    {
        foreach (self::AWS_KEYS as $key) {
            $this->originalEnvironment[$key] ??= [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];

            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach ($environment as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        // Dotenv's repository is immutable and remembers the variables it has
        // already loaded, so on a second boot it would overwrite the values
        // set here with the blank ones from `.env`. Resetting the repository
        // makes it treat these as externally defined and leave them alone.
        Env::enablePutenv();

        $this->refreshApplication();
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->originalEnvironment as $key => $original) {
            $this->restoreValue($_ENV, $key, $original['env']);
            $this->restoreValue($_SERVER, $key, $original['server']);

            if ($original['getenv'] === false) {
                putenv($key);
            } else {
                putenv($key.'='.$original['getenv']);
            }
        }

        $this->originalEnvironment = [];
    }

    /**
     * @param  array<string, string>  $target
     */
    private function restoreValue(array &$target, string $key, ?string $value): void
    {
        if ($value === null) {
            unset($target[$key]);
        } else {
            $target[$key] = $value;
        }
    }
}
