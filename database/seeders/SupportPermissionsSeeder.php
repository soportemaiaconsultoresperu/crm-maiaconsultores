<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SupportPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    private array $permissions = [
        'support.view.any',
        'support.view.team',
        'support.view.own',
        'support.create',
        'support.update',
        'support.assign',
        'support.reassign',
'support.priority.update',
        'support.schedule',
        'support.reschedule',
        'support.attention.start',
        'support.observations.create',
        'support.observations.lift',
        'support.observations.validate',
        'support.observations.reject',
        'support.resolve',
        'support.close',
        'support.close.with-pending-observations',
        'support.reopen',
        'support.updates.create',
        'support.cancel',
        'support.reports.view',
        'support.catalogs.manage',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->grant('admin', $this->permissions);
        $this->grant('supervisor', [
            'support.view.team',
            'support.create',
            'support.update',
            'support.assign',
            'support.reassign',
            'support.priority.update',
            'support.schedule',
            'support.reschedule',
            'support.attention.start',
            'support.observations.create',
            'support.observations.lift',
            'support.observations.validate',
            'support.observations.reject',
            'support.resolve',
            'support.close',
            'support.close.with-pending-observations',
            'support.reopen',
            'support.updates.create',
            'support.cancel',
            'support.reports.view',
        ]);
        $this->grant('vendedor', [
            'support.view.own',
            'support.create',
            'support.update',
            'support.updates.create',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** @param  list<string>  $permissions */
    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

        if ($role === null) {
            return;
        }

        $role->syncPermissions(array_values(array_unique(array_merge(
            $role->permissions->pluck('name')->all(),
            $permissions,
        ))));
    }
}
