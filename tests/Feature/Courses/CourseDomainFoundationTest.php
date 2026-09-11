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
        foreach (['course_activities', 'course_editions', 'course_sessions', 'course_participants', 'course_enrollment_groups', 'course_enrollments', 'course_attendances', 'course_grades', 'course_certificate_templates', 'course_academic_documents', 'course_commercial_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertSame(0.18, config('courses.igv_rate'));
        $this->assertSame(1, config('courses.delivery_due_days'));
        $this->assertSame('PEN', config('courses.default_currency'));
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
}
