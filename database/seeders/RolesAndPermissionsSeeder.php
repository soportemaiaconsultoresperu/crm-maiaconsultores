<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles (admin / supervisor / vendedor) and granular permissions with
 * the {module}.{action}.{scope} convention.
 *
 * Application code must check PERMISSIONS ONLY, never role names.
 * Idempotent: permissions and roles are created via firstOrCreate;
 * assignments use syncPermissions so re-seeding converges.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Modules that follow the owner-based data scope (any/team/own).
     */
    private array $scopedModules = [
        'leads',
        'customers',
        'contacts',
        'opportunities',
        'activities',
        'quotations',
    ];

/**
     * Extra per-module permissions beyond view/create/update/deactivate.
     */
    private array $moduleExtras = [
        'leads' => ['leads.assign', 'leads.import', 'leads.export', 'leads.convert'],
        'opportunities' => ['opportunities.win', 'opportunities.lose'],
        'activities' => ['activities.complete', 'activities.delete'],
        'quotations' => ['quotations.accept'],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $all = $this->createPermissions();

        // admin: everything.
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($all);

// supervisor: team visibility, create/update, reports and audit.
        $supervisorPermissions = [];
        foreach ($this->scopedModules as $module) {
            $supervisorPermissions[] = "{$module}.view.team";
            $supervisorPermissions[] = "{$module}.create";
            $supervisorPermissions[] = "{$module}.update";
        }
        $supervisorPermissions[] = 'activities.complete';
        $supervisorPermissions[] = 'activities.delete';
        $supervisorPermissions[] = 'calendar.view';
$supervisorPermissions[] = 'documents.view.team';
        $supervisorPermissions[] = 'documents.download';
        $supervisorPermissions[] = 'documents.upload';
        $supervisorPermissions[] = 'reports.view';
        $supervisorPermissions[] = 'audit.view';
        $supervisor = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisor->syncPermissions(array_intersect($supervisorPermissions, $all));

// vendedor: own visibility, create/update, export/convert, pipeline actions.
        $vendedorPermissions = [];
        foreach ($this->scopedModules as $module) {
            $vendedorPermissions[] = "{$module}.view.own";
            $vendedorPermissions[] = "{$module}.create";
            $vendedorPermissions[] = "{$module}.update";
        }
        $vendedorPermissions[] = 'activities.complete';
        $vendedorPermissions[] = 'activities.delete';
$vendedorPermissions = array_merge($vendedorPermissions, [
            'leads.export',
            'leads.convert',
            'opportunities.win',
            'opportunities.lose',
            'quotations.accept',
            'calendar.view',
            'documents.view.own',
            'documents.upload',
            'documents.download',
            'documents.delete',
            // Reports (RF-REP-001): vendedor sees the dashboard and the
            // standard reports for their own data; data scope is what
            // actually restricts visibility (ADR-006).
            'reports.view',
        ]);
        $vendedor = Role::firstOrCreate(['name' => 'vendedor', 'guard_name' => 'web']);
        $vendedor->syncPermissions(array_intersect($vendedorPermissions, $all));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Create every permission and return the full list of names.
     *
     * @return list<string>
     */
    private function createPermissions(): array
    {
        $names = [];

        foreach ($this->scopedModules as $module) {
            $names[] = "{$module}.view.any";
            $names[] = "{$module}.view.team";
            $names[] = "{$module}.view.own";
            $names[] = "{$module}.create";
            $names[] = "{$module}.update";
            $names[] = "{$module}.deactivate";
            $names[] = "{$module}.export";

            foreach ($this->moduleExtras[$module] ?? [] as $extra) {
                $names[] = $extra;
            }
        }

$names = array_merge($names, [
            // Products: global catalog, no team/own scope.
            'products.view.any',
            'products.create',
            'products.update',
            'products.deactivate',
            'products.export',
            // Documents (B09 / RF-DOC-001..005, ADR-011).
            'documents.view.any',
            'documents.view.team',
            'documents.view.own',
            'documents.upload',
            'documents.download',
            'documents.delete',
            // Calendar.
            'calendar.view',
            // Reports / settings / users / teams / audit.
            'reports.view',
            'settings.manage',
            'users.manage',
            'teams.manage',
            'audit.view',
        ]);

        $names = array_values(array_unique($names));

        foreach ($names as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        return $names;
    }
}
