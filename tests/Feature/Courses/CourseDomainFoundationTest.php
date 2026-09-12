<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\CourseModality;
use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_foundation_tables_and_defaults_exist(): void
    {
        // Exactly the 12 tables 2026_08_26_000001 creates: course_edition_teachers
        // included, because it is the one table the migration adds without an
        // `id` column and the module writes through it.
        foreach (['course_activities', 'course_editions', 'course_edition_teachers', 'course_sessions', 'course_participants', 'course_enrollment_groups', 'course_enrollments', 'course_attendances', 'course_grades', 'course_certificate_templates', 'course_academic_documents', 'course_commercial_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertSame(0.18, config('courses.igv_rate'));
        $this->assertSame(1, config('courses.delivery_due_days'));
        $this->assertSame('PEN', config('courses.default_currency'));
        $this->assertSame(10080, config('courses.email_document_link_minutes'));
    }

    /**
     * Defect B — the migration's `down()` drops all 12 domain tables and nothing
     * else. The private PDFs survive with no row left to locate them, and the
     * surviving `documents` rows can end up pointing at a DIFFERENT certificate
     * once MySQL reuses the AUTO_INCREMENT ids. No test drives that rollback, so
     * the damage is recorded as documentation; this test fails if the record is
     * deleted.
     */
    public function test_the_schema_rollback_damage_is_documented(): void
    {
        $doc = $this->knownLimitations();

        $this->assertStringContainsString('migrate:rollback', $doc);
        $this->assertStringContainsString('AUTO_INCREMENT', $doc);
        $this->assertStringContainsString('storage/app/private/docs', $doc);
    }

    /**
     * Finding 5 — `course_commercial_documents.status = 'sent'` is read by three
     * surfaces and written by nothing in `app/`. It stays as a reserved value
     * (the design lists it), and the reservation is recorded here so a future
     * reader can tell a deliberate value from a dead branch.
     */
    public function test_the_reserved_commercial_status_is_documented(): void
    {
        $doc = $this->knownLimitations();

        $this->assertStringContainsString('course_commercial_documents.status', $doc);
        $this->assertStringContainsString('reserved', $doc);
    }

    public function test_activity_codes_and_enrollments_are_unique(): void
    {
        CourseActivity::create(['type' => CourseActivityType::Course, 'code' => 'CUR-SAN-001', 'name' => 'Saneamiento', 'slug' => 'saneamiento', 'official_academic_hours' => '8.00']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CourseActivity::create(['type' => CourseActivityType::Talk, 'code' => 'CUR-SAN-001', 'name' => 'Duplicado', 'slug' => 'duplicado', 'official_academic_hours' => '2.00']);
    }

    public function test_models_cast_enums_and_resolve_relationships_for_group_enrollments(): void
    {
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['state' => CourseEditionState::Scheduled, 'modality' => CourseModality::Hybrid]);
        $participant = CourseParticipant::factory()->create();
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);
        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->for($group, 'group')->create(['state' => CourseEnrollmentState::Confirmed, 'payment_status' => PaymentStatus::Paid]);
        $session = CourseSession::factory()->for($edition, 'edition')->create();

        $this->assertSame(CourseEditionState::Scheduled, $enrollment->edition->state);
        $this->assertSame(CourseModality::Hybrid, $edition->modality);
        $this->assertSame('Maia Consultores', $enrollment->group->payer_name);
        $this->assertTrue($edition->sessions->contains($session));
        $this->assertTrue($participant->enrollments->contains($enrollment));
    }

    public function test_enrollment_uniqueness_is_per_edition_and_participant(): void
    {
        $edition = CourseEdition::factory()->create();
        $participant = CourseParticipant::factory()->create();
        CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create();
    }

    private function knownLimitations(): string
    {
        // The record must be found whether the change is still ACTIVE or already
        // ARCHIVED: closing a change moves its artifacts under
        // `openspec/changes/archive/<date>-<name>/`, so hardcoding the active path
        // made these two tests fail the moment the change was archived. The
        // assertion is about the record existing, not about where it lives.
        $candidates = [
            base_path('openspec/changes/course-talks-management/known-limitations.md'),
        ];

        foreach (glob(base_path('openspec/changes/archive/*-course-talks-management/known-limitations.md')) ?: [] as $archived) {
            $candidates[] = $archived;
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        throw new \RuntimeException(
            'El registro de limitaciones del cambio debe existir, en el change activo o en el archivado.'
        );
    }
}
