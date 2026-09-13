<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Events\Courses\CourseActivityChanged;
use App\Events\Courses\CourseEditionChanged;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CourseActivityService;
use App\Services\Courses\CourseEditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CourseActivityEditionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_service_rejects_duplicate_manual_codes_and_emits_course_audit_event(): void
    {
        Event::fake([CourseActivityChanged::class]);
        $service = new CourseActivityService;

        $activity = $service->create([
            'type' => CourseActivityType::Course,
            'code' => ' CUR-SAN-001 ',
            'name' => 'Saneamiento ambiental',
            'official_academic_hours' => '8.00',
            'base_syllabus_json' => ['Introducción'],
        ]);

        $this->assertSame('CUR-SAN-001', $activity->code);
        Event::assertDispatched(CourseActivityChanged::class, fn (CourseActivityChanged $event) => $event->activityId === $activity->id && $event->event === 'course-activity-created');

        $this->expectException(InvalidCourseEditionData::class);
        $service->create([
            'type' => CourseActivityType::Talk,
            'code' => 'CUR-SAN-001',
            'name' => 'Charla repetida',
            'official_academic_hours' => '1.00',
            'base_syllabus_json' => [],
        ]);
    }

    public function test_course_validation_completion_requests_eligibility_for_each_enrollment_after_commit(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->state(['type' => CourseActivityType::Course]), 'activity')
            ->create();
        $enrollments = CourseEnrollment::factory()->count(2)->for($edition, 'edition')->create();

        app(CourseEditionService::class)->completeValidations($edition);

        $this->assertNotNull($edition->fresh()->validations_completed_at);
        foreach ($enrollments as $enrollment) {
            Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
                $event->enrollmentId === $enrollment->id && $event->reason === 'edition_validation'
            );
            Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
                $job->enrollmentId === $enrollment->id && $job->reason === 'edition_validation' && $job->afterCommit === true
            );
        }
    }

    public function test_incomplete_or_talk_editions_do_not_request_eligibility(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $service = app(CourseEditionService::class);
        $incompleteCourse = CourseEdition::factory()
            ->for(CourseActivity::factory()->state(['type' => CourseActivityType::Course]), 'activity')
            ->create();
        $talk = CourseEdition::factory()
            ->for(CourseActivity::factory()->state(['type' => CourseActivityType::Talk]), 'activity')
            ->create();
        CourseEnrollment::factory()->for($incompleteCourse, 'edition')->create();
        CourseEnrollment::factory()->for($talk, 'edition')->create();

        $service->transitionState($incompleteCourse, CourseEditionState::Scheduled);
        $service->completeValidations($talk);

        $this->assertNull($incompleteCourse->fresh()->validations_completed_at);
        $this->assertNotNull($talk->fresh()->validations_completed_at);
        Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
        Queue::assertNothingPushed();
    }

    public function test_validation_completion_does_not_request_eligibility_when_its_transaction_rolls_back(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->state(['type' => CourseActivityType::Course]), 'activity')
            ->create();
        CourseEnrollment::factory()->for($edition, 'edition')->create();

        try {
            DB::transaction(function () use ($edition): void {
                app(CourseEditionService::class)->completeValidations($edition);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull($edition->fresh()->validations_completed_at);
        Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
        Queue::assertNothingPushed();
    }

    public function test_edition_service_validates_modality_manages_teachers_and_sessions_and_emits_events(): void
    {
        Event::fake([CourseEditionChanged::class]);
        $activity = CourseActivity::factory()->create();
        $service = new CourseEditionService;

        $edition = $service->create($activity, [
            'code' => ' ED-SAN-001 ',
            'modality' => 'hybrid',
            'address' => ' Sede Lima ',
            'access_url' => ' https://meet.example.test/san ',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-02',
            'price_amount' => '150.00',
        ]);
        $service->syncTeachers($edition, [
            ['display_name' => 'Ana Docente', 'email' => 'ana@example.test'],
            ['display_name' => 'Luis Docente'],
        ]);
        $service->syncSessions($edition, [
            ['session_date' => '2026-09-01', 'starts_at' => '09:00', 'ends_at' => '11:00', 'teacher_name' => 'Ana Docente', 'topic' => 'Inicio'],
            ['session_date' => '2026-09-02', 'starts_at' => '09:00', 'ends_at' => '11:00', 'teacher_name' => 'Luis Docente', 'topic' => 'Cierre'],
        ]);
        $service->transitionState($edition, CourseEditionState::Scheduled);

        $this->assertDatabaseHas('course_editions', ['id' => $edition->id, 'code' => 'ED-SAN-001', 'modality' => 'hybrid', 'address' => 'Sede Lima', 'access_url' => 'https://meet.example.test/san', 'state' => 'scheduled']);
        $this->assertDatabaseCount('course_edition_teachers', 2);
        $this->assertDatabaseHas('course_sessions', ['course_edition_id' => $edition->id, 'sort_order' => 2, 'topic' => 'Cierre']);
        Event::assertDispatched(CourseEditionChanged::class, fn (CourseEditionChanged $event) => $event->editionId === $edition->id && $event->event === 'course-edition-state-changed');
    }
}
