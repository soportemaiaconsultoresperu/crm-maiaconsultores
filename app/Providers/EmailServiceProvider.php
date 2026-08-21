<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B13 — Email module wiring.
 *
 *  - register(): no service bindings yet (adapters, senders and listeners
 *    land in B13 Pasada B / C).
 *  - boot(): explicit {@see registerEmailPermissions()} that materialises
 *    the six B13 permissions and grants the read-only set to admin and
 *    supervisor. The V1 permission count tests stay stable because we only
 *    add new permissions, never alter or remove existing ones.
 *
 *  Pattern is intentionally identical to {@see AutomationServiceProvider}:
 *  guarded by Schema::hasTable('permissions') inside a try/catch so the
 *  boot path never fails when the DB is unreachable or the schema is not
 *  yet migrated (the dedicated seeder catches up afterwards).
 */
class EmailServiceProvider extends ServiceProvider
{
    /**
     * Permissions introduced by B13.
     *
     *  - email.send           —	push through the outbound pipeline.
     *  - email.template.manage —	CRUD/version templates.
     *  - email.shared.use     —	use a shared {@see IntegrationAccount} from
     *                             the shared pool (decision 9c).
     *  - email.account.manage —	connect / disconnect vendor accounts.
     *  - email.view           —	read-only inbox + history.
     *  - email.audit          —	see audit fields for emails.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'email.send',
        'email.template.manage',
        'email.shared.use',
        'email.account.manage',
        'email.view',
        'email.audit',
    ];

    /**
     * Permissions granted to the supervisor role (audit + template.manage
     * stay admin-only for v1 per the proposal).
     *
     * @var list<string>
     */
    public const SUPERVISOR_GRANTS = [
        'email.view',
    ];

    /**
     * Permissions granted to the admin role (everything).
     *
     * @var list<string>
     */
    public const ADMIN_GRANTS = self::PERMISSIONS;

    public function register(): void
    {
        // B13 Pasada B — service bindings. Kept lazy so tests can swap the
        // provider factory without touching the rest of the framework.
        $this->app->singleton(
            \App\Contracts\Email\EmailProviderFactory::class,
            fn () => new \App\Contracts\Email\EmailProviderFactory(),
        );

        $this->app->singleton(
            \App\Services\Email\EmailTemplateRenderer::class,
            function ($app) {
                $allowed = (array) config('email.allowed_variables', []);
                $allowed = array_values(array_filter($allowed, 'is_string'));

                return new \App\Services\Email\EmailTemplateRenderer($allowed);
            },
        );

        $this->app->singleton(
            \App\Services\Email\EmailService::class,
            fn ($app) => new \App\Services\Email\EmailService(
                $app->make(\App\Services\Email\EmailTemplateRenderer::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->registerEmailPermissions();
    }

    /**
     * B13 — ensure the six email permissions exist and that admin and
     * supervisor receive the right grants. Idempotent and registered at
     * boot time so V1 permission counts (84) stay unchanged.
     */
    public function registerEmailPermissions(): void
    {
        // Guard for early boot contexts (e.g. test bootstrap before the
        // Spatie permission tables exist, or the configured DB is unreachable).
        // If the schema isn't ready or the DB connection fails, the permission
        // registrations will be performed by the dedicated seeder in
        // production deployments — never break the boot path with a fatal here.
        try {
            if (! Schema::hasTable('permissions')) {
                return;
            }
        } catch (\Throwable $e) {
            // DB unreachable (driver down, credentials wrong, schema not yet
            // migrated). Exit silently; the dedicated seeder will catch up.
            if (function_exists('logger')) {
                logger()->debug('EmailServiceProvider: permissions guard skipped', [
                    'reason' => $e::class,
                ]);
            }
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin !== null) {
            $existing = $admin->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, self::ADMIN_GRANTS)));
            $admin->syncPermissions($merged);
        }

        $supervisor = Role::query()
            ->where('name', 'supervisor')
            ->where('guard_name', 'web')
            ->first();

        if ($supervisor !== null) {
            $existing = $supervisor->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, self::SUPERVISOR_GRANTS)));
            $supervisor->syncPermissions($merged);
        }
    }
}
