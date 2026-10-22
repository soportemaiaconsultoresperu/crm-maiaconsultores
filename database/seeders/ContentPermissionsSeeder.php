<?php

namespace Database\Seeders;

use App\Support\Permissions\SeededGrants;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Content scheduling module permission vocabulary (task T2).
 *
 * Same rollout contract as `CoursePermissionsSeeder` and
 * `SupportPermissionsSeeder`:
 *
 * - Rows are created with `firstOrCreate`, so re-running is idempotent.
 * - Role grants go through `SeededGrants::grantOnce()`: each default is handed
 *   to a role once and recorded in the `seeded_role_grants` ledger, so a re-run
 *   (production seeds on every deploy) never revokes nor re-grants what the
 *   admin adjusted on the role screen. This seeder never deletes a row and
 *   never revokes a grant; every change it makes is additive.
 * - It runs after `RolesAndPermissionsSeeder`, which creates the roles.
 *
 * `content_projects.view_all` is the only cross-project widening and it stays
 * admin-only here on purpose: the consolidated view is expressed as a
 * PERMISSION, never as a hardcoded role name, so another role can be given it
 * later without touching application code.
 */
class ContentPermissionsSeeder extends Seeder
{
    /**
     * The whole vocabulary. Order is the order it is created in.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'content_projects.view',
        'content_projects.create',
        'content_projects.update',
        'content_projects.archive',
        'content_projects.assign_members',
        'content_projects.view_all',
    ];

    /**
     * Role => the slice of the vocabulary that role holds.
     *
     * The operational (non-administrative) role gets the READ-ONLY subset only.
     * That permission opens the module; per-record reach is still narrowed by
     * membership in `ContentProjectScopeService`, so holding
     * `content_projects.view` never grants another user's project.
     *
     * No operational role needs a blanket grant to WORK in a project: project
     * membership alone opens the module and the content of that project
     * (`ContentProjectPolicy::update`/`trash`). The `content_projects.*`
     * permissions below stay administrative (configure, members, lifecycle).
     *
     * @var array<string, list<string>>
     */
    private const ROLE_GRANTS = [
        'admin' => self::PERMISSIONS,
        'supervisor' => ['content_projects.view'],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::ROLE_GRANTS as $roleName => $permissions) {
            $this->grant($roleName, $permissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Grant each default to the role once, never dropping what it already holds.
     *
     * @param  list<string>  $permissions
     */
    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            return;
        }

        SeededGrants::grantOnce($role, $permissions);
    }
}
