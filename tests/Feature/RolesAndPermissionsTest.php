<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

public function test_exactly_67_permissions_are_seeded(): void
    {
        // 62 baseline (B01-B04) + 2 added in B05 for activities.complete /
        // activities.delete (RF-ACT-005/006/008 fine-grained permissions) +
        // 1 added in B06 for products.export (RF-PROD-001) +
        // 2 added in B09 for documents.upload / documents.delete
        // (RF-DOC-001..005, ADR-011).
        $this->assertSame(67, Permission::count());
    }

    public function test_three_initial_roles_exist(): void
    {
        foreach (['admin', 'supervisor', 'vendedor'] as $roleName) {
            $this->assertTrue(
                Role::where('name', $roleName)->exists(),
                "Role [{$roleName}] must exist."
            );
        }

        $this->assertSame(3, Role::count());
    }

public function test_admin_role_holds_every_baseline_permission(): void
    {
        $admin = Role::where('name', 'admin')->first();

        $this->assertSame(67, $admin->permissions()->count());
        $this->assertTrue($admin->hasPermissionTo('leads.view.any'));
        $this->assertTrue($admin->hasPermissionTo('quotations.accept'));
        $this->assertTrue($admin->hasPermissionTo('products.export'));
        $this->assertTrue($admin->hasPermissionTo('audit.view'));
        $this->assertTrue($admin->hasPermissionTo('activities.complete'));
        $this->assertTrue($admin->hasPermissionTo('activities.delete'));
    }

    public function test_vendedor_has_own_scope_but_not_team_or_any(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');

        $this->assertTrue($vendedor->can('leads.view.own'));
        $this->assertTrue($vendedor->can('leads.create'));
        $this->assertFalse($vendedor->can('leads.view.any'));
        $this->assertFalse($vendedor->can('leads.view.team'));
        $this->assertFalse($vendedor->can('users.manage'));
    }

    public function test_supervisor_has_team_scope_but_not_any(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $this->assertTrue($supervisor->can('leads.view.team'));
        $this->assertTrue($supervisor->can('leads.create'));
        $this->assertTrue($supervisor->can('reports.view'));
        $this->assertFalse($supervisor->can('leads.view.any'));
        $this->assertFalse($supervisor->can('users.manage'));
    }

public function test_b08_admin_permissions_are_added_by_additional_seeder(): void
    {
        // Without AdditionalPermissionsSeeder (this setUp), the B08
        // permissions do not exist yet. Re-run the seed and verify the
        // expected counts. The seeder lists 14 B08 permissions but
        // settings.manage and teams.manage already existed from the B01
        // seeder, so firstOrCreate makes them no-ops. Together with the
        // 5 B06 additions the total grows from 67 to 84.
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $this->assertSame(84, Permission::count(), '17 net additions (5 B06 + 12 B08) bring the total from 67 to 84. B12 automations.* permissions are registered by AutomationServiceProvider::boot() at runtime, not by AdditionalPermissionsSeeder, so the seeder-only count stays 84.');
    }

    public function test_admin_gets_every_new_b08_permission(): void
    {
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $admin = Role::where('name', 'admin')->first();

        $expected = [
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
        ];

        foreach ($expected as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission),
                "Admin must hold [{$permission}]."
            );
        }

        $this->assertSame(79, $admin->permissions()->count());
    }

    public function test_supervisor_gets_read_only_admin_perms_plus_manage_for_teams_catalogs_settings(): void
    {
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $supervisor = Role::where('name', 'supervisor')->first();

        $this->assertTrue($supervisor->hasPermissionTo('users.view'));
        $this->assertTrue($supervisor->hasPermissionTo('teams.view'));
        $this->assertTrue($supervisor->hasPermissionTo('teams.manage'));
        $this->assertTrue($supervisor->hasPermissionTo('roles.view'));
        $this->assertTrue($supervisor->hasPermissionTo('catalogs.view'));
        $this->assertTrue($supervisor->hasPermissionTo('catalogs.manage'));
        $this->assertTrue($supervisor->hasPermissionTo('settings.view'));
        $this->assertTrue($supervisor->hasPermissionTo('settings.manage'));

        // Sensitive: explicitly forbidden.
        $this->assertFalse($supervisor->hasPermissionTo('users.create'));
        $this->assertFalse($supervisor->hasPermissionTo('users.update'));
        $this->assertFalse($supervisor->hasPermissionTo('users.deactivate'));
        $this->assertFalse($supervisor->hasPermissionTo('users.reset_password'));
        $this->assertFalse($supervisor->hasPermissionTo('users.assign_role'));
        $this->assertFalse($supervisor->hasPermissionTo('roles.manage'));
    }

    public function test_vendedor_has_no_b08_admin_permission(): void
    {
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $vendedor = Role::where('name', 'vendedor')->first();

        foreach ([
            'users.view',
            'teams.view',
            'teams.manage',
            'roles.view',
            'catalogs.view',
            'catalogs.manage',
            'settings.view',
            'settings.manage',
        ] as $permission) {
            $this->assertFalse(
                $vendedor->hasPermissionTo($permission),
                "Vendedor must NOT hold [{$permission}]."
            );
        }
    }

    public function test_permission_checks_work_without_role_names(): void
    {
        // Authorization must rely on permissions only (RF-USR-003):
        // a user without roles has no module access.
        $plain = User::factory()->create();

        $this->assertFalse($plain->can('leads.view.own'));
        $this->assertFalse($plain->can('leads.view.any'));
    }
}
