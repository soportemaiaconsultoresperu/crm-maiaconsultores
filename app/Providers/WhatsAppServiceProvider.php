<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B14 — WhatsApp module wiring.
 *
 *  - register(): no service bindings yet. Concrete bindings for the
 *    {@see \App\Integrations\Contracts\WhatsAppProvider} adapter, senders,
 *    listeners and the webhook middleware land in B14 Pasada B / C.
 *  - boot(): explicit {@see registerWhatsAppPermissions()} that
 *    materialises the six B14 permissions and grants the read-only
 *    set to admin and supervisor. The V1 permission count tests stay
 *    stable because we only add new permissions, never alter or remove
 *    existing ones.
 *
 *  Pattern is intentionally identical to {@see EmailServiceProvider}:
 *  guarded by Schema::hasTable('permissions') inside a try/catch so the
 *  boot path never fails when the DB is unreachable or the schema is not
 *  yet migrated (the dedicated seeder catches up afterwards).
 *
 *  Per docs/v2/01-roadmap.md §2.4 and §7 decisions 12–15.
 *
 * @see docs/v2/01-roadmap.md §7 decision 12b (contract swap-ready — B11
 *      shipped the WhatsAppProvider interface; B14 Pasada B provides the
 *      Meta Cloud API implementation that satisfies it).
 */
class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Permissions introduced by B14.
     *
     *  - whatsapp.view             — read-only inbox + history.
     *  - whatsapp.send              —	push outbound messages through Meta.
     *  - whatsapp.template.manage   —	manage the local template catalogue mirror.
     *  - whatsapp.account.manage    —	connect / disconnect vendor accounts.
     *  - whatsapp.conversation.assign —	assign conversations to users / teams.
     *  - whatsapp.audit             —	see audit fields (consent log + deliveries).
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'whatsapp.view',
        'whatsapp.send',
        'whatsapp.template.manage',
        'whatsapp.account.manage',
        'whatsapp.conversation.assign',
        'whatsapp.audit',
    ];

    /**
     * Permissions granted to the supervisor role for v1 (read-only view).
     * Send / template.manage / account.manage / conversation.assign /
     * audit stay admin-only per the B14 proposal.
     *
     * @var list<string>
     */
    public const SUPERVISOR_GRANTS = [
        'whatsapp.view',
    ];

    /**
     * Permissions granted to the admin role (everything).
     *
     * @var list<string>
     */
    public const ADMIN_GRANTS = self::PERMISSIONS;

    public function register(): void
    {
        // B14 Pasada B-1 — service bindings for the outbound pipeline.
        //
        // The factory resolves a concrete {@see \App\Contracts\WhatsApp\WhatsAppProvider}
        // for a given {@see \App\Models\WhatsApp\WhatsAppAccount}. Today it
        // returns the Meta Cloud API adapter (decision 12a); future BSPs
        // (Twilio, MessageBird) drop in here without changing call sites
        // (decision 12b — contract swap-ready).
        $this->app->singleton(
            \App\Contracts\WhatsApp\WhatsAppProviderFactory::class,
            fn () => new \App\Contracts\WhatsApp\WhatsAppProviderFactory(),
        );

$this->app->singleton(
            \App\Services\WhatsApp\WhatsAppService::class,
            fn ($app) => new \App\Services\WhatsApp\WhatsAppService(
                $app->make(\App\Contracts\WhatsApp\WhatsAppProviderFactory::class),
            ),
        );

        // B14 Pasada B-2 — register the console command so it is
        // discoverable even when running with a stripped autoload
        // (e.g. `php artisan list` in production builds).
        $this->commands([
            \App\Console\Commands\SyncWhatsAppTemplates::class,
        ]);
    }

    public function boot(): void
    {
        $this->registerWhatsAppPermissions();
    }

    /**
     * B14 — ensure the six WhatsApp permissions exist and that admin and
     * supervisor receive the right grants. Idempotent and registered at
     * boot time so V1 permission counts (84 + 6 email = 90) stay
     * deterministic.
     */
    public function registerWhatsAppPermissions(): void
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
                logger()->debug('WhatsAppServiceProvider: permissions guard skipped', [
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
