<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\DeliveryStatus;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseAlertService;
use App\Services\Courses\CourseEligibilityService;
use Database\Seeders\CoursePermissionsSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Slice 7 unit 7.d — rollout seeding and rollback controls.
 *
 * Every other course test seeds `CoursePermissionsSeeder` itself, proving the
 * module works once a fixture hand-seeded the permissions. This suite runs the
 * REAL `DatabaseSeeder` and asks whether a deploy is usable: the permissions
 * exist, the intended roles hold them, and the entry surface opens. It FAILED
 * before unit 7.d because `DatabaseSeeder` never called the seeder, so a fresh
 * `php artisan db:seed` left the module unreachable for every non-admin role.
 *
 * The rollback half asserts the design's non-destructive rule: hiding the
 * module by permission hides routes and menu and deletes nothing; a QR link is
 * revoked only by an explicit annulment, never by hiding access or discarding a
 * follow-up; and the eligibility job is a documented no-op (nothing to stop).
 */
class CourseRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_full_seed_creates_the_module_permissions_and_assigns_them_to_the_intended_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertCount(13, CoursePermissionsSeeder::PERMISSIONS, 'The module ships 13 permissions.');
        foreach (CoursePermissionsSeeder::PERMISSIONS as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->where('guard_name', 'web')->exists(),
                "Permission [{$name}] must exist after the real full seed."
            );
        }
        // TRIANGULATE: exactly the shipped set, no accidental 14th row and no orphan left by a prior seed.
        $this->assertSame(13, Permission::where('name', 'like', 'course-talks.%')->count(), 'The full seed must create exactly the shipped 13 course-talks.* permissions, no more.');

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
        foreach (CoursePermissionsSeeder::PERMISSIONS as $name) {
            $this->assertTrue($admin->hasPermissionTo($name), "admin must hold [{$name}] after the full seed.");
        }

        // The shipped assignment is read-only for supervisor: the module read surface, nothing that writes.
        // Granting more would be a product decision the design does not make, so the seeder's explicit grant is pinned here.
        $supervisor = Role::where('name', 'supervisor')->where('guard_name', 'web')->firstOrFail();
        $this->assertTrue($supervisor->hasPermissionTo('course-talks.view'));
        foreach (['course-talks.activities.manage', 'course-talks.documents.revoke', 'course-talks.documents.send', 'course-talks.templates.manage', 'course-talks.audit.view'] as $management) {
            $this->assertFalse($supervisor->hasPermissionTo($management), "supervisor must NOT hold [{$management}] — the rollout grants read access only.");
        }
    }

    public function test_an_admin_from_the_real_full_seed_opens_the_module_and_sees_its_sidebar_entry(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->firstOrFail();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->hasPermissionTo('course-talks.view'), 'The seeded admin must hold the module permission, not only the role.');

        $dashboard = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->assertStringContainsString('data-testid="sidebar-course-talks"', (string) $dashboard->getContent(), 'The seeded admin did not see the module sidebar entry.');
        $dashboard->assertSee('Cursos y charlas');
        $this->actingAs($admin)->get(route('course-talks.activities.index'))->assertOk();
    }

    public function test_a_supervisor_from_the_real_full_seed_reaches_the_module_read_surface(): void
    {
        $this->seed(DatabaseSeeder::class);

        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $dashboard = $this->actingAs($supervisor)->get(route('dashboard'))->assertOk();
        $this->assertStringContainsString('data-testid="sidebar-course-talks"', (string) $dashboard->getContent(), 'A supervisor from the real full seed did not see the module sidebar entry.');
        $this->actingAs($supervisor)->get(route('course-talks.activities.index'))->assertOk();
    }

    public function test_a_user_without_any_course_permission_is_denied_and_sees_no_entry_after_the_full_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $stranger = User::factory()->create(['is_active' => true]);

        $dashboard = $this->actingAs($stranger)->get(route('dashboard'))->assertOk();
        $this->assertStringNotContainsString('data-testid="sidebar-course-talks"', (string) $dashboard->getContent(), 'The rollout must not hand the module entry to a user without course-talks.view.');
        $this->actingAs($stranger)->get(route('course-talks.activities.index'))->assertForbidden();
    }

    public function test_re_seeding_the_real_full_seed_does_not_duplicate_permissions_or_role_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $baseline = [
            'module-permissions' => Permission::whereIn('name', CoursePermissionsSeeder::PERMISSIONS)->count(),
            'total-permissions' => Permission::count(),
            'admin-permissions' => Role::where('name', 'admin')->firstOrFail()->permissions()->count(),
            'supervisor-permissions' => Role::where('name', 'supervisor')->firstOrFail()->permissions()->count(),
            'admin-role-assignments' => User::query()->first()->roles()->count(),
        ];
        $this->assertSame(13, $baseline['module-permissions'], 'The module must contribute exactly its 13 permissions to the full seed.');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($baseline['module-permissions'], Permission::whereIn('name', CoursePermissionsSeeder::PERMISSIONS)->count(), 'Re-seeding duplicated module permissions.');
        $this->assertSame($baseline['total-permissions'], Permission::count(), 'Re-seeding duplicated permissions.');
        $this->assertSame($baseline['admin-permissions'], Role::where('name', 'admin')->firstOrFail()->permissions()->count(), 'Re-seeding duplicated the admin assignment.');
        $this->assertSame($baseline['supervisor-permissions'], Role::where('name', 'supervisor')->firstOrFail()->permissions()->count(), 'Re-seeding duplicated the supervisor assignment.');
        $this->assertSame(1, $baseline['admin-role-assignments'], 'The bootstrap admin keeps exactly one role assignment.');
    }

    public function test_removing_the_module_view_permission_hides_the_routes_and_the_menu_without_touching_data(): void
    {
        Storage::fake('docs');
        $this->seed(DatabaseSeeder::class);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('supervisor');
        $fixture = $this->moduleFixture();

        // Baseline: with the seeded permission the supervisor opens the module.
        $this->actingAs($viewer)->get(route('course-talks.activities.index'))->assertOk();
        $dashboard = $this->actingAs($viewer)->get(route('dashboard'))->assertOk();
        $this->assertStringContainsString('data-testid="sidebar-course-talks"', (string) $dashboard->getContent());

        $activityLogCount = Activity::count();

        // ROLLBACK: revoke the permission that granted access. Nothing else.
        Role::where('name', 'supervisor')->firstOrFail()->revokePermissionTo('course-talks.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $dashboard = $this->actingAs($viewer)->get(route('dashboard'))->assertOk();
        $this->assertStringNotContainsString('data-testid="sidebar-course-talks"', (string) $dashboard->getContent(), 'A revoked permission must hide the module menu entry.');
        // TRIANGULATE: the whole module hides, not only the activity list.
        foreach (['activities.index', 'alerts.index', 'templates.index'] as $route) {
            $this->actingAs($viewer)->get(route("course-talks.{$route}"))->assertForbidden();
        }

        // ...and the rollback changed no data: rows, private files, QR token and audit trail are intact.
        $this->assertDatabaseHas('course_activities', ['id' => $fixture['activity']->id]);
        $this->assertDatabaseHas('course_academic_documents', ['id' => $fixture['academic']->id]);
        $this->assertDatabaseHas('course_commercial_documents', ['id' => $fixture['commercial']->id]);
        $this->assertDatabaseHas('documents', ['id' => $fixture['academic']->document_id]);
        $this->assertDatabaseHas('documents', ['id' => $fixture['commercial']->document_id]);
        $this->assertTrue(Storage::disk('docs')->exists($fixture['academic']->document->path), 'The generated certificate file was deleted by the rollback.');
        $this->assertTrue(Storage::disk('docs')->exists($fixture['commercial']->document->path), 'The uploaded attachment was deleted by the rollback.');

        $academic = $fixture['academic']->fresh();
        $this->assertNotNull($academic->qr_token_hash, 'The rollback wiped the QR token.');
        $this->assertSame(AcademicDocumentStatus::Current, $academic->status);
        $this->assertSame($activityLogCount, Activity::count(), 'The rollback deleted or wrote audit rows.');
        $this->assertTrue(
            Activity::where('description', 'course-updated')->where('subject_id', $fixture['activity']->id)->exists(),
            'The audit row for the activity change did not survive the rollback.'
        );

        // Re-granting restores access: the rollback is reversible and data-neutral.
        Role::where('name', 'supervisor')->firstOrFail()->givePermissionTo('course-talks.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer)->get(route('course-talks.activities.index'))->assertOk();
    }

    /**
     * Rollback reality, measured. Access for every non-admin role is governed by the `course-talks.*`
     * permissions, so revoking them hides the module from supervisor/vendedor. The seeded ADMIN also
     * reaches the module through the role-based `Gate::before` bypass in `AuthServiceProvider`, so hiding
     * it from admin is a PRODUCT decision (drop the role or the bypass), not a permission-only rollback.
     * This test records that reality instead of claiming a control that does not exist.
     */
    public function test_revoking_the_permission_hides_the_module_from_non_admin_roles_while_admin_keeps_the_role_bypass(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->firstOrFail();
        $this->assertTrue($admin->hasRole('admin'));

        Role::where('name', 'admin')->firstOrFail()->revokePermissionTo('course-talks.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertFalse($admin->hasPermissionTo('course-talks.view'), 'The revoke must really remove the permission.');
        $this->actingAs($admin)->get(route('course-talks.activities.index'))->assertOk();
    }

    public function test_a_qr_link_is_revoked_only_by_an_explicit_annulment_never_by_hiding_access_or_discarding_a_follow_up(): void
    {
        Storage::fake('docs');
        $this->seed(DatabaseSeeder::class);

        $fixture = $this->moduleFixture();
        $academic = $fixture['academic'];
        $token = $fixture['token'];

        $this->get("/certificate/qr/{$token}")->assertOk();

        // 1. Hiding access: the module hides, the link stays live.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('supervisor');
        Role::where('name', 'supervisor')->firstOrFail()->revokePermissionTo('course-talks.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer)->get(route('course-talks.activities.index'))->assertForbidden();
        $this->assertNull($academic->fresh()->qr_token_revoked_at);
        $this->get("/certificate/qr/{$token}")->assertOk();

        // 2. A user without the revocation permission cannot annul, so cannot revoke the QR as a side effect of trying.
        $this->actingAs($viewer)->post(route('course-talks.documents.annul', $academic), ['reason' => 'Anulación no autorizada'])->assertForbidden();
        $this->assertNull($academic->fresh()->qr_token_revoked_at);
        $this->get("/certificate/qr/{$token}")->assertOk();

        // 3. Discarding the delivery follow-up closes the follow-up only: the document keeps its status, file and live QR.
        $operator = User::factory()->create(['is_active' => true]);
        $operator->givePermissionTo('course-talks.documents.send');
        app(CourseAlertService::class)->discard($academic->fresh(), 'No se entregará por decisión del cliente.', $operator);

        $academic = $academic->fresh();
        $this->assertSame(DeliveryStatus::Discarded, $academic->delivery_status);
        $this->assertNull($academic->qr_token_revoked_at, 'Discarding a follow-up must not revoke the QR link.');
        $this->assertSame(AcademicDocumentStatus::Current, $academic->status);
        $this->assertTrue(Storage::disk('docs')->exists($academic->document->path), 'Discarding a follow-up must not delete the generated file.');
        $this->get("/certificate/qr/{$token}")->assertOk();

        // 4. Only an explicit annulment with the right permission revokes it.
        $revoker = User::factory()->create(['is_active' => true]);
        $revoker->givePermissionTo('course-talks.documents.revoke');
        app(CertificateQrTokenService::class)->revoke($academic, $revoker, 'Corrección solicitada');

        $this->assertNotNull($academic->fresh()->qr_token_revoked_at);
        $this->get("/certificate/qr/{$token}")->assertNotFound();
    }

    /**
     * The design's "stop eligibility jobs through queue/config" control, measured: the module's only
     * eligibility job, `EvaluateCourseDocumentEligibility`, is a DOCUMENTED no-op — it evaluates and
     * returns without generating a document or writing a file. There is therefore no asynchronous
     * generation a permission rollback would have to stop, and no queue/config switch is introduced.
     */
    public function test_the_only_eligibility_job_is_a_documented_no_op_so_a_rollback_has_no_job_to_stop(): void
    {
        Storage::fake('docs');
        $this->seed(DatabaseSeeder::class);

        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'state' => CourseEditionState::InProgress,
            'validations_completed_at' => now(),
        ]);
        $participant = CourseParticipant::factory()->create();
        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create([
            'state' => CourseEnrollmentState::Completed,
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        $eligibility = app(CourseEligibilityService::class);
        $this->assertTrue(
            $eligibility->evaluate($enrollment->fresh())->eligible,
            'The probe enrollment must be eligible, otherwise the job returns early and this test proves nothing.'
        );

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'rollout-control-probe'))->handle($eligibility);

        $this->assertSame(0, CourseAcademicDocument::count(), 'The eligibility job must not generate documents (it is a no-op).');
        $this->assertSame([], Storage::disk('docs')->allFiles(), 'The eligibility job must not write files (it is a no-op).');
    }

    /**
     * A minimal but real module graph: one activity, its edition, one enrollment, a current certificate
     * with a private PDF and a live QR token, and a registered comprobante with its own private attachment.
     * One activity rename forces an audit row so the rollback tests can prove it survives.
     *
     * @return array{activity: CourseActivity, academic: CourseAcademicDocument, commercial: CourseCommercialDocument, token: string}
     */
    private function moduleFixture(): array
    {
        $activity = CourseActivity::factory()->create(['code' => 'CUR-ROLLOUT-1', 'name' => 'Curso de despliegue']);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-ROLLOUT-1']);
        $enrollment = CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for(CourseParticipant::factory(), 'participant')
            ->create();
        $uploader = User::factory()->create(['is_active' => true]);

        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'AC-ROLLOUT-1',
            'issue_date' => now()->toDateString(),
            'filename' => 'AC-ROLLOUT-1.pdf',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $academicPath = 'course-academic-documents/'.$enrollment->id.'/AC-ROLLOUT-1.pdf';
        Storage::disk('docs')->put($academicPath, '%PDF certificado de despliegue');
        $academic->forceFill(['document_id' => Document::query()->create($this->documentAttributes($academicPath, CourseAcademicDocument::class, $academic->id, $uploader->id))->id])->save();

        $commercial = CourseCommercialDocument::query()->create([
            'course_enrollment_id' => $enrollment->id,
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B001',
            'number' => '000123',
            'issue_date' => now()->toDateString(),
            'currency' => 'PEN',
            'subtotal_amount' => '100.00',
            'igv_rate' => 0.18,
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Maia Consultores',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $commercialPath = 'course-commercial-documents/'.$enrollment->id.'/B001-000123.pdf';
        Storage::disk('docs')->put($commercialPath, '%PDF comprobante de despliegue');
        $commercial->forceFill(['document_id' => Document::query()->create($this->documentAttributes($commercialPath, CourseCommercialDocument::class, $commercial->id, $uploader->id))->id])->save();

        $token = app(CertificateQrTokenService::class)->createFor($academic->fresh());

        // Force one audit row that the rollback tests assert survives.
        $activity->forceFill(['name' => 'Curso de despliegue (renombrado)'])->save();

        return [
            'activity' => $activity,
            'academic' => $academic->fresh()->load('document'),
            'commercial' => $commercial->fresh()->load('document'),
            'token' => $token,
        ];
    }

    /** @return array<string, mixed> */
    private function documentAttributes(string $path, string $docableType, int $docableId, int $uploadedBy): array
    {
        return [
            'docable_type' => $docableType,
            'docable_id' => $docableId,
            'uploaded_by' => $uploadedBy,
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 4096,
            'uploaded_at' => now(),
        ];
    }
}
