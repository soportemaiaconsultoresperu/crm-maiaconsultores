<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{getenv: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $originalAdminEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $key) {
            $this->originalAdminEnvironment[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        $this->setAdminEnvironment('Deployment Admin', 'admin@maiaconsultores.com', 'initial-password');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalAdminEnvironment as $key => $environment) {
            putenv($environment['getenv'] === false ? $key : "{$key}={$environment['getenv']}");

            if ($environment['env_exists']) {
                $_ENV[$key] = $environment['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($environment['server_exists']) {
                $_SERVER[$key] = $environment['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        parent::tearDown();
    }

    private function setAdminEnvironment(string $name, string $email, string $password): void
    {
        foreach ([
            'ADMIN_NAME' => $name,
            'ADMIN_EMAIL' => $email,
            'ADMIN_PASSWORD' => $password,
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public function test_admin_seeder_creates_and_updates_the_application_admin_idempotently(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->sole();

        $this->assertSame('Deployment Admin', $admin->name);
        $this->assertSame('admin@maiaconsultores.com', $admin->email);
        $this->assertTrue(Hash::check('initial-password', $admin->password));
        $this->assertTrue($admin->hasRole('admin'));

        $this->setAdminEnvironment('Updated Deployment Admin', 'admin@maiaconsultores.com', 'updated-password');
        $this->seed(AdminUserSeeder::class);

        $admin->refresh();
        $this->assertSame(1, User::count());
        $this->assertSame('Updated Deployment Admin', $admin->name);
        $this->assertTrue(Hash::check('updated-password', $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
    }
}
