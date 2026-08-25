<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Ordered seeding: permissions first (roles reference them), then the
 * admin user (needs the role), then catalogs, ubigeo and settings.
 * No fake business data is seeded.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdditionalPermissionsSeeder::class,
            SupportPermissionsSeeder::class,
            AdminUserSeeder::class,
            CatalogSeeder::class,
            SupportCatalogSeeder::class,
            UbigeoSeeder::class,
            SettingsSeeder::class,
            CodeSequencesSeeder::class,
        ]);
    }
}
