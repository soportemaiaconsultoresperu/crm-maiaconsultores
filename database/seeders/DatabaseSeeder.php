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
            // access. It also does NOT stop the module's eligibility job,
            // `App\Jobs\Courses\EvaluateCourseDocumentEligibility`: the module
            // generates certificates automatically when the last missing condition
            // completes, and that generation is attributed to the dedicated SYSTEM
            // account below — not to the user who completed the condition — so it
            // depends on neither that user's permissions nor the roles seeded here.
            // The switch that DOES stop it is `courses.automatic_document_generation_enabled`
            // in `config/courses.php`: set it to `false` and the job returns before
            // it evaluates anything, so a permission-only rollback leaves no
            // asynchronous generation running. NOTE: the seeded ADMIN still reaches the
            // module through the role-based `Gate::before` bypass in
            // `App\Providers\AuthServiceProvider`; hiding it from admin is a
            // product decision (drop the role or the bypass), not a permission-only
            // rollback.
            CoursePermissionsSeeder::class,
            AdminUserSeeder::class,
            // The SYSTEM author of automatic document generation. It runs after
            // `CoursePermissionsSeeder` (which creates the single permission it
            // holds) and after `AdminUserSeeder`, so the bootstrap admin stays the
            // first user row — the ordering `SeedersTest` and the automation actions
            // already rely on.
            CourseSystemAuthorSeeder::class,
            CatalogSeeder::class,
            SupportCatalogSeeder::class,
            UbigeoSeeder::class,
            SettingsSeeder::class,
            CodeSequencesSeeder::class,
        ]);
    }
}
