<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B21 — Seeder for the 4 consent module permissions.
 *
 * Permissions introduced by B21:
 *   - consent.view    → list consent_records + suppression_entries (any authenticated user)
 *   - consent.grant   → grant consent (agent + admin)
 *   - consent.revoke  → revoke consent (agent + admin)
 *   - consent.audit   → full audit log access (admin only)
 *
 * Idempotent: firstOrCreate + syncPermissions. Safe to re-run.
 */
class AdditionalConsentPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'consent.view',
        'consent.grant',
        'consent.revoke',
        'consent.audit',
    ];

    public const ADMIN_GRANTS = self::PERMISSIONS;
    public const SUPERVISOR_GRANTS = ['consent.view', 'consent.audit'];

    public function run(): void
    {
        if (! DB::table('permissions')->where('guard_name', 'web')->exists()) {
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
