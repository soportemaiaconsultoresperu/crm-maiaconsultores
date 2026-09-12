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
            // Rollout path for the Cursos y charlas module. `RolesAndPermissionsSeeder`
            // syncs the roles, so this MUST run after it (and after the other
            // permission seeders): otherwise the module permissions would be wiped.
            // It is idempotent on re-seed (firstOrCreate + merge-then-sync).
            //
            // ROLLBACK (per design "Keep rollback non-destructive"): to take the
            // module out of service, remove this call — or revoke the
            // `course-talks.*` permissions from the roles — and the routes and the
            // sidebar entry (gated on `viewAny` -> `course-talks.view`) stop being
            // reachable. That rollback deletes NOTHING: generated documents, uploaded
            // attachments, delivered history and audit rows stay in the database and
            // their private files stay on the private disk; a QR link is revoked only
            // by an explicit annul/regeneration, never as a side effect of hiding
            // access. The module's only eligibility job,
            // `App\Jobs\Courses\EvaluateCourseDocumentEligibility`, is a documented
            // no-op (it evaluates and writes nothing), so there is no asynchronous
            // generation to drain or stop. NOTE: the seeded ADMIN still reaches the
            // module through the role-based `Gate::before` bypass in
            // `App\Providers\AuthServiceProvider`; hiding it from admin is a
            // product decision (drop the role or the bypass), not a permission-only
            // rollback.
            CoursePermissionsSeeder::class,
            AdminUserSeeder::class,
            CatalogSeeder::class,
            SupportCatalogSeeder::class,
            UbigeoSeeder::class,
            SettingsSeeder::class,
            CodeSequencesSeeder::class,
        ]);
    }
}
