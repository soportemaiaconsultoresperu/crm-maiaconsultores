<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\FinalResult;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseAttendance;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\User;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Services\Courses\CourseAttendanceService;
use App\Services\Courses\CourseGradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class CourseAttendanceAndGradesTest extends TestCase
{
    use RefreshDatabase;

    public function test_talk_editions_reject_grade_recording(): void
    {
        [$session, $enrollment] = $this->courseSeat(CourseActivityType::Talk);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Talk editions do not accept grades.');

        app(CourseGradeService::class)->record($session, $enrollment, '15.00', 'Principal');
    }

    public function test_records_one_principal_grade_per_session_and_persists_approval_boundary(): void
    {
        [$session, $enrollment] = $this->courseSeat();
        $actor = User::factory()->create();

        $grade = app(CourseGradeService::class)->record($session, $enrollment, '12.50', 'Clase 1', $actor);

        $this->assertDatabaseHas('course_grades', [
            'id' => $grade->id,
            'course_session_id' => $session->id,
            'course_enrollment_id' => $enrollment->id,
            'description' => 'Clase 1',
            'grade' => '12.50',
            'entered_by' => $actor->id,
        ]);

        $enrollment->refresh();
        $this->assertSame('12.5000', $enrollment->exact_average);
        $this->assertSame('12.50', $enrollment->display_average);
        $this->assertSame(13, $enrollment->rounded_result);
        $this->assertSame(FinalResult::Approved, $enrollment->final_result);
    }

    public function test_course_grade_recording_requests_eligibility_after_the_result_is_recalculated(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        [$session, $enrollment] = $this->courseSeat();

        app(CourseGradeService::class)->record($session, $enrollment, '13.00', 'Final grade');

        $this->assertSame(FinalResult::Approved, $enrollment->fresh()->final_result);
        Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
            $event->enrollmentId === $enrollment->id && $event->reason === 'grade'
        );
        Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
            $job->enrollmentId === $enrollment->id && $job->reason === 'grade' && $job->afterCommit === true
        );
    }

    public function test_grade_recording_does_not_request_eligibility_when_the_enclosing_transaction_rolls_back(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        [$session, $enrollment] = $this->courseSeat();

        DB::beginTransaction();
        try {
            app(CourseGradeService::class)->record($session, $enrollment, '13.00', 'Rolled back grade');
            Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
            Queue::assertNotPushed(EvaluateCourseDocumentEligibility::class);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('course_grades', 0);
        $this->assertSame(FinalResult::Pending, $enrollment->fresh()->final_result);
        Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
        Queue::assertNotPushed(EvaluateCourseDocumentEligibility::class);
    }

    public function test_correction_replaces_existing_session_grade_and_recalculates_participation_boundary(): void
    {
        [$firstSession, $enrollment] = $this->courseSeat();
        $secondSession = CourseSession::factory()->for($enrollment->edition, 'edition')->create(['sort_order' => 2]);
        $service = app(CourseGradeService::class);

        $service->record($firstSession, $enrollment, '13.00', 'Original');
        $corrected = $service->record($firstSession, $enrollment, '12.00', 'Corrected');
        $service->record($secondSession, $enrollment, '12.98', 'Second');

        $this->assertSame(1, $firstSession->grades()->where('course_enrollment_id', $enrollment->id)->count());
        $this->assertSame($corrected->id, $firstSession->grades()->where('course_enrollment_id', $enrollment->id)->first()->id);

        $enrollment->refresh();
        $this->assertSame('12.4900', $enrollment->exact_average);
        $this->assertSame('12.49', $enrollment->display_average);
        $this->assertSame(12, $enrollment->rounded_result);
        $this->assertSame(FinalResult::Participation, $enrollment->final_result);
    }

    public function test_talk_attendance_marks_participation_and_requests_eligibility_after_commit(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        [$session, $enrollment] = $this->courseSeat(CourseActivityType::Talk);
        $actor = User::factory()->create();

        $attendance = app(CourseAttendanceService::class)->mark($session, $enrollment, 'present', $actor);

        $this->assertDatabaseHas('course_attendances', [
            'id' => $attendance->id,
            'course_session_id' => $session->id,
            'course_enrollment_id' => $enrollment->id,
            'status' => 'present',
            'marked_by' => $actor->id,
        ]);
        $this->assertNotNull($enrollment->fresh()->participation_confirmed_at);
        Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
            $event->enrollmentId === $enrollment->id && $event->reason === 'participation'
        );
        Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
            $job->enrollmentId === $enrollment->id && $job->reason === 'participation' && $job->afterCommit === true
        );
    }

    public function test_attendance_rejects_a_session_and_enrollment_from_different_editions(): void
    {
        [$session] = $this->courseSeat();
        [, $otherEnrollment] = $this->courseSeat();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('same edition');

        app(CourseAttendanceService::class)->mark($session, $otherEnrollment, 'present');
    }

    public function test_course_attendance_is_informational_and_does_not_request_document_eligibility(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        [$session, $enrollment] = $this->courseSeat();
        $service = app(CourseAttendanceService::class);

        $service->mark($session, $enrollment, 'present');
        $updated = $service->mark($session, $enrollment, 'absent');

        $this->assertSame('absent', $updated->status);
        $this->assertDatabaseCount('course_attendances', 1);
        $this->assertNull($enrollment->fresh()->participation_confirmed_at);
        Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
        Queue::assertNotPushed(EvaluateCourseDocumentEligibility::class);
    }

    public function test_course_attendance_is_informational_for_grade_result_and_decimal_average_has_no_float_drift(): void
    {
        [$firstSession, $enrollment] = $this->courseSeat();
        $secondSession = CourseSession::factory()->for($enrollment->edition, 'edition')->create(['sort_order' => 2]);
        $thirdSession = CourseSession::factory()->for($enrollment->edition, 'edition')->create(['sort_order' => 3]);

        CourseAttendance::create([
            'course_session_id' => $firstSession->id,
            'course_enrollment_id' => $enrollment->id,
            'status' => 'absent',
            'marked_at' => now(),
        ]);

        $service = app(CourseGradeService::class);
        $service->record($firstSession, $enrollment, '10.10');
        $service->record($secondSession, $enrollment, '10.20');
        $service->record($thirdSession, $enrollment, '10.30');

        $enrollment->refresh();
        $this->assertSame('10.2000', $enrollment->exact_average);
        $this->assertSame('10.20', $enrollment->display_average);
        $this->assertSame(10, $enrollment->rounded_result);
        $this->assertSame(FinalResult::Participation, $enrollment->final_result);
    }

    /** @return array{0: CourseSession, 1: CourseEnrollment} */
    private function courseSeat(CourseActivityType $type = CourseActivityType::Course): array
    {
        $activity = CourseActivity::factory()->create(['type' => $type]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create();
        $participant = CourseParticipant::factory()->create();
        $enrollment = CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create(['state' => CourseEnrollmentState::Completed]);
        $session = CourseSession::factory()->for($edition, 'edition')->create();

        return [$session, $enrollment];
    }
}
