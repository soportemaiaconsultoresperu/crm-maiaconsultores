<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CoursePermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'course-talks.view',
        'course-talks.activities.manage',
        'course-talks.editions.manage',
        'course-talks.sessions.manage',
        'course-talks.attendance.manage',
        'course-talks.grades.manage',
        'course-talks.participants.manage',
        'course-talks.documents.generate',
        'course-talks.documents.revoke',
        'course-talks.commercial-documents.manage',
        'course-talks.documents.send',
        'course-talks.templates.manage',
        'course-talks.audit.view',
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

        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin !== null) {
            $existing = $admin->permissions->pluck('name')->all();
            $admin->syncPermissions(array_values(array_unique(array_merge($existing, self::PERMISSIONS))));
        }

        $supervisor = Role::query()
            ->where('name', 'supervisor')
            ->where('guard_name', 'web')
            ->first();

        if ($supervisor !== null) {
            $existing = $supervisor->permissions->pluck('name')->all();
            $supervisor->syncPermissions(array_values(array_unique(array_merge($existing, ['course-talks.view']))));
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
