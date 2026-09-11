<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Services\Courses\CourseEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_paid_course_with_complete_data_and_validated_edition_is_eligible_for_approval_certificate(): void
    {
        $result = app(CourseEligibilityService::class)->evaluate($this->seat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]));

        $this->assertTrue($result->eligible);
        $this->assertSame([], $result->missingConditions);
        $this->assertSame(AcademicDocumentType::ApprovalCertificate, $result->documentType);
    }

    public function test_authorized_waived_course_participation_result_is_eligible_for_participation_constancy(): void
    {
        $result = app(CourseEligibilityService::class)->evaluate($this->seat([
            'payment_status' => PaymentStatus::Waived,
            'final_result' => FinalResult::Participation,
        ]));

        $this->assertTrue($result->eligible);
        $this->assertSame(AcademicDocumentType::ParticipationConstancy, $result->documentType);
    }

    public function test_unpaid_invalid_participant_unvalidated_course_reports_explicit_missing_conditions(): void
    {
        $enrollment = $this->seat(
            ['payment_status' => PaymentStatus::Partial, 'final_result' => FinalResult::Pending],
            ['validations_completed_at' => null],
            [],
            ['email' => null]
        );

        $result = app(CourseEligibilityService::class)->evaluate($enrollment);

        $this->assertFalse($result->eligible);
        $this->assertSame(['payment', 'participant_data', 'edition_validations', 'academic_result'], $result->missingConditions);
        $this->assertNull($result->documentType);
    }

    public function test_refunded_withdrawn_and_no_show_enrollments_are_ineligible(): void
    {
        foreach ([CourseEnrollmentState::Withdrawn, CourseEnrollmentState::NoShow] as $state) {
            $result = app(CourseEligibilityService::class)->evaluate($this->seat([
                'state' => $state,
                'payment_status' => PaymentStatus::Paid,
                'final_result' => FinalResult::Approved,
            ]));

            $this->assertFalse($result->eligible);
            $this->assertSame(['enrollment_state'], $result->missingConditions);
            $this->assertNull($result->documentType);
        }

        $refunded = app(CourseEligibilityService::class)->evaluate($this->seat([
            'payment_status' => PaymentStatus::Refunded,
            'final_result' => FinalResult::Approved,
        ]));

        $this->assertFalse($refunded->eligible);
        $this->assertContains('payment', $refunded->missingConditions);
    }

    public function test_talk_requires_confirmed_participation_and_certificate_enabled(): void
    {
        $missingParticipation = app(CourseEligibilityService::class)->evaluate($this->seat(
            ['payment_status' => PaymentStatus::Paid, 'final_result' => FinalResult::NotApplicable],
            [],
            ['type' => CourseActivityType::Talk, 'talk_includes_certificate' => true]
        ));

        $this->assertFalse($missingParticipation->eligible);
        $this->assertSame(['participation'], $missingParticipation->missingConditions);

        $withoutCertificate = app(CourseEligibilityService::class)->evaluate($this->seat(
            ['payment_status' => PaymentStatus::Paid, 'final_result' => FinalResult::NotApplicable, 'participation_confirmed_at' => now()],
            [],
            ['type' => CourseActivityType::Talk, 'talk_includes_certificate' => false]
        ));

        $this->assertFalse($withoutCertificate->eligible);
        $this->assertSame(['participation'], $withoutCertificate->missingConditions);
    }

    public function test_confirmed_paid_talk_with_certificate_is_eligible_for_talk_certificate(): void
    {
        $result = app(CourseEligibilityService::class)->evaluate($this->seat(
            ['payment_status' => PaymentStatus::Paid, 'final_result' => FinalResult::NotApplicable, 'participation_confirmed_at' => now()],
            [],
            ['type' => CourseActivityType::Talk, 'talk_includes_certificate' => true]
        ));

        $this->assertTrue($result->eligible);
        $this->assertSame([], $result->missingConditions);
        $this->assertSame(AcademicDocumentType::TalkCertificate, $result->documentType);
    }

    private function seat(array $enrollment = [], array $edition = [], array $activity = [], array $participant = []): CourseEnrollment
    {
        $activity = CourseActivity::factory()->create(array_merge(['type' => CourseActivityType::Course], $activity));
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(array_merge(['validations_completed_at' => now()], $edition));
        $participant = CourseParticipant::factory()->create($participant);

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
