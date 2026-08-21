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
            $existing = $admin->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, self::ADMIN_GRANTS)));
            $admin->syncPermissions($merged);
        }

        $supervisor = Role::query()->where('name', 'supervisor')->where('guard_name', 'web')->first();
        if ($supervisor !== null) {
            $existing = $supervisor->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, self::SUPERVISOR_GRANTS)));
            $supervisor->syncPermissions($merged);
        }
    }
}
