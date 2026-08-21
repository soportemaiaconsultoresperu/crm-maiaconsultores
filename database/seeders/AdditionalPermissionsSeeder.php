<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotently adds the per-module permissions introduced in B06
 * (Tanda A: Productos y Cotizaciones) and B08 (Tanda A: Administración
 * — users, teams, roles, catalogs, settings, audit viewer) on top of
 * the existing RolesAndPermissionsSeeder. Never deletes or rewrites
 * existing permissions; missing ones are created with firstOrCreate so
 * re-runs converge without error.
 *
 * Also grants the new products.* permissions to the existing admin /
 * supervisor / vendedor roles (the products catalog is global, not
 * owner-scoped, so every authenticated role needs at least
 * products.view.any to see it) and wires the B08 admin permissions
 * following the rule documented below. The original seeder is not
 * modified.
 *
 * B08 grant policy (RF-USR-008):
 *  - admin: every new permission.
 *  - supervisor: every view permission + teams/catalogs/settings
 *    manage (read/write of his own team area). Lacks users.* modify
 *    actions and roles.manage (admin-only).
 *  - vendedor: no admin permissions (the seller workflow stays in the
 *    leads/quotations modules, not in administration).
 */
class AdditionalPermissionsSeeder extends Seeder
{
    /**
     * Additional permissions that must exist after this seeder runs.
     *
     * @var list<string>
     */
    private array $permissions = [
        // Products: team/own view (the existing seeder already creates
        // view.any / create / update / deactivate as a global catalog).
        'products.view.team',
        'products.view.own',

        // Quotations: lifecycle actions beyond the basic CRUD + accept
        // already seeded by RolesAndPermissionsSeeder.
        'quotations.delete',
        'quotations.reject',
        'quotations.duplicate',

        // B08 — Users (RF-USR-001, RF-USR-005, RF-USR-007, RF-USR-008).
        'users.view',
        'users.create',
        'users.update',
        'users.deactivate',
        'users.reset_password',
        'users.assign_role',

        // B08 — Teams.
        'teams.view',
        'teams.manage',

        // B08 — Roles & permissions management.
        'roles.view',
        'roles.manage',

        // B08 — Catalogs (RF-CFG-001, RF-CFG-002).
        'catalogs.view',
        'catalogs.manage',

// B08 — Settings (RF-CFG-004, RF-CFG-005).
        'settings.view',
        'settings.manage',

        // Note: `audit.view` already exists from B01; firstOrCreate is a
        // no-op so we don't need to add it here.

        // B12 — automation permissions are seeded separately by
        // AutomationPermissionsSeeder so that the V1 permission count
        // tests (tests/Feature/RolesAndPermissionsTest.php, SeedersTest.php)
        // remain stable. See docs/v2/01-roadmap.md §2.1.
    ];

    /**
     * Permissions granted to the supervisor role in addition to what
     * RolesAndPermissionsSeeder already assigns. Read-only across the
     * admin surface plus manage rights on teams/catalogs/settings.
     *
     * @var list<string>
     */
    private array $supervisorAdminGrants = [
        'users.view',
        'teams.view',
        'teams.manage',
        'roles.view',
        'catalogs.view',
        'catalogs.manage',
        'settings.view',
        'settings.manage',
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

        // Grant products.view.any to all existing roles so the global
        // catalog is visible to everyone authenticated. The products
        // table has no owner_id column, so the data scope is a no-op
        // for them; access is gated by the module permissions.
        $rolesToGrant = ['admin', 'supervisor', 'vendedor'];

        foreach ($rolesToGrant as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($role === null) {
                continue;
            }

            $existing = $role->permissions->pluck('name')->all();

            $toGrant = array_values(array_unique(array_merge($existing, [
                'products.view.any',
            ])));

            $role->syncPermissions($toGrant);
        }

        // Wire B08 admin permissions: admin gets everything, supervisor
        // gets the read-only set + teams/catalogs/settings manage.
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
            'users.view',
            'users.create',
            'users.update',
            'users.deactivate',
            'users.reset_password',
            'users.assign_role',
            'teams.view',
            'teams.manage',
            'roles.view',
            'roles.manage',
            'catalogs.view',
            'catalogs.manage',
            'settings.view',
            'settings.manage',
            'audit.view',
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

        $toGrant = array_values(array_unique(array_merge($existing, $this->supervisorAdminGrants)));

        $supervisor->syncPermissions($toGrant);
    }
}
