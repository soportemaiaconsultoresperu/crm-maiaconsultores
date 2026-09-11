<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ConsentService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B21 — Consent + suppression module wiring.
 *
 * - register(): singleton `ConsentService`.
 * - boot(): register the 4 B21 permissions, gate `auth` already wires the
 *   `consent.*` middleware via `can:` in routes / controllers.
 *
 * Mirrors `EmailServiceProvider` and `WhatsAppServiceProvider` patterns.
 */
class ConsentServiceProvider extends ServiceProvider
{
    public const PERMISSIONS = \Database\Seeders\AdditionalConsentPermissionsSeeder::PERMISSIONS;
    public const ADMIN_GRANTS = \Database\Seeders\AdditionalConsentPermissionsSeeder::ADMIN_GRANTS;
    public const SUPERVISOR_GRANTS = \Database\Seeders\AdditionalConsentPermissionsSeeder::SUPERVISOR_GRANTS;

    public function register(): void
    {
        $this->app->singleton(ConsentService::class, fn () => new ConsentService());
    }

    public function boot(): void
    {
        $this->registerConsentPermissions();
    }

    public function registerConsentPermissions(): void
    {
        try {
            if (! Schema::hasTable('permissions')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin !== null) {
            // Grant additively instead of syncPermissions(): givePermissionTo()
            // only attaches the missing role_has_permissions rows, whereas
            // syncPermissions() detaches every row and re-inserts it, which
            // races on the role_has_permissions primary key when parallel
            // artisan processes boot against the same DB.
            $admin->givePermissionTo(self::ADMIN_GRANTS);
        }

        $supervisor = Role::query()->where('name', 'supervisor')->where('guard_name', 'web')->first();
        if ($supervisor !== null) {
            // Additive grant — see the admin branch above for the
            // concurrent-boot duplicate-key rationale.
            $supervisor->givePermissionTo(self::SUPERVISOR_GRANTS);
        }
    }
}
