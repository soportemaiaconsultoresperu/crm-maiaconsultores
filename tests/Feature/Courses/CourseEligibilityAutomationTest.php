<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Services\Courses\CourseEligibilityTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CourseEligibilityAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_changes_request_eligibility_after_commit_without_running_generation_inline(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $enrollment = $this->eligibleSeat();

        app(CourseEligibilityTriggerService::class)->paymentChanged($enrollment);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new CourseEligibilityEvaluationRequested($enrollment->id, 'payment'));
        Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
            $event->enrollmentId === $enrollment->id && $event->reason === 'payment'
        );
        Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
            $job->enrollmentId === $enrollment->id
            && $job->reason === 'payment'
            && $job->afterCommit === true
        );
        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_grade_participation_and_edition_validation_changes_can_request_evaluation(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $enrollment = $this->eligibleSeat();
        $service = app(CourseEligibilityTriggerService::class);

        $service->gradeChanged($enrollment);
        $service->participationChanged($enrollment);
        $service->editionValidationChanged($enrollment);

        foreach (['grade', 'participation', 'edition_validation'] as $reason) {
            Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
                $event->enrollmentId === $enrollment->id && $event->reason === $reason
            );
            Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
                $job->enrollmentId === $enrollment->id && $job->reason === $reason
            );
        }
    }

    public function test_job_evaluates_eligibility_but_does_not_create_documents_or_mutate_enrollment_data(): void
    {
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
            'exact_average' => '15.2500',
            'display_average' => '15.25',
            'rounded_result' => 15,
        ]);
        $before = $enrollment->fresh()->only([
            'state', 'payment_status', 'exact_average', 'display_average', 'rounded_result', 'final_result', 'participation_confirmed_at',
        ]);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(\App\Services\Courses\CourseEligibilityService::class));

        $this->assertDatabaseCount('course_academic_documents', 0);
        $this->assertSame($before, $enrollment->fresh()->only(array_keys($before)));
    }

    public function test_job_is_idempotent_when_current_document_already_exists(): void
    {
        $enrollment = $this->eligibleSeat();
        CourseAcademicDocument::create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CUR-EXISTING-001',
            'delivery_status' => 'pending',
        ]);

        $eligibility = app(\App\Services\Courses\CourseEligibilityService::class);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle($eligibility);
        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle($eligibility);

        $this->assertDatabaseCount('course_academic_documents', 1);
        $this->assertDatabaseHas('course_academic_documents', [
            'course_enrollment_id' => $enrollment->id,
            'code' => 'CUR-EXISTING-001',
            'status' => AcademicDocumentStatus::Current->value,
        ]);
    }

    private function eligibleSeat(array $enrollment = []): CourseEnrollment
    {
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['validations_completed_at' => now()]);
        $participant = CourseParticipant::factory()->create();

        return CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create(array_merge([
                'state' => CourseEnrollmentState::Completed,
                'payment_status' => PaymentStatus::Paid,
                'final_result' => FinalResult::Approved,
            ], $enrollment));
    }
}
