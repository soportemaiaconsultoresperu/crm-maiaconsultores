<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            // Plantillas (4)
            'campaign_templates.view',
            'campaign_templates.create',
            'campaign_templates.update',
            'campaign_templates.duplicate',
            // Ejecuciones (9)
            'campaigns.view',
            'campaigns.create',
            'campaigns.update',
            'campaigns.schedule',
            'campaigns.start',
            'campaigns.pause',
            'campaigns.complete',
            'campaigns.cancel',
            'campaigns.duplicate',
            // Contactos y pasos (4)
            'campaigns.add_contacts',
            'campaigns.remove_contacts',
            'campaigns.register_actions',
            'campaigns.reschedule',
            // Operación (3)
            'campaigns.mark_realized',
            'campaigns.view_reports',
            'campaigns.override_completion',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Auto-sufficient role creation so the migration works on both MySQL
        // (where RolesAndPermissionsSeeder has already run) and SQLite in-memory
        // tests (where seeders do not run). Existing roles are not duplicated;
        // existing permissions are preserved (givePermissionTo is idempotent).
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->givePermissionTo($permissions);

        $supervisor = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisor->givePermissionTo($permissions);

        $vendedor = Role::firstOrCreate(['name' => 'vendedor', 'guard_name' => 'web']);
        $vendedor->givePermissionTo([
            'campaigns.view',
            'campaigns.reschedule',
            'campaigns.mark_realized',
            'campaigns.view_reports',
        ]);
    }

    public function down(): void
    {
        $permissionNames = [
            'campaign_templates.view', 'campaign_templates.create', 'campaign_templates.update', 'campaign_templates.duplicate',
            'campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.schedule', 'campaigns.start',
            'campaigns.pause', 'campaigns.complete', 'campaigns.cancel', 'campaigns.duplicate',
            'campaigns.add_contacts', 'campaigns.remove_contacts', 'campaigns.register_actions', 'campaigns.reschedule',
            'campaigns.mark_realized', 'campaigns.view_reports', 'campaigns.override_completion',
        ];

        $roles = Role::all();
        foreach ($roles as $role) {
            $role->revokePermissionTo($permissionNames);
        }

        Permission::whereIn('name', $permissionNames)->delete();
    }
};
