<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * B13 — Idempotent seeder for the email module permissions.
 *
 * Always created via firstOrCreate so re-running the seeder is a no-op.
 * Also grants the right baseline to admin (everything) and supervisor
 * (read-only view) when those roles exist.
 *
 * The companion {@see \App\Providers\EmailServiceProvider} runs the same
 * logic at boot time so permission state is available even before this
 * seeder is invoked, but this seeder exists so the change can be applied
 * to existing environments where the provider boot already happened
 * before these permissions were added.
 *
 * Never deletes or rewrites existing permissions; missing ones are created
 * with firstOrCreate so the original V1 count (84) stays untouched.
 */
class AdditionalEmailPermissionsSeeder extends Seeder
{
    /**
     * Permissions that must exist after this seeder runs.
     *
     * @var list<string>
     */
    private array $permissions = [
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
    private array $supervisorGrants = [
        'email.view',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->permissions as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $this->grantAdmin();
        $this->grantSupervisor();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function grantAdmin(): void
    {
        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin === null) {
            return;
        }

        $existing = $admin->permissions->pluck('name')->all();

        $toGrant = array_values(array_unique(array_merge($existing, [
            'email.send',
            'email.template.manage',
            'email.shared.use',
            'email.account.manage',
            'email.view',
            'email.audit',
        ])));

        $admin->syncPermissions($toGrant);
    }

    private function grantSupervisor(): void
    {
        $supervisor = Role::query()
            ->where('name', 'supervisor')
            ->where('guard_name', 'web')
            ->first();

        if ($supervisor === null) {
            return;
        }

        $existing = $supervisor->permissions->pluck('name')->all();

        $toGrant = array_values(array_unique(array_merge($existing, $this->supervisorGrants)));

        $supervisor->syncPermissions($toGrant);
    }
}
