<?php

namespace Tests\Feature\Courses;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Courses\CourseEdition;
use App\Enums\Courses\PaymentStatus;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CourseEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class CourseEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_an_existing_contact_and_rejects_a_duplicate_edition_enrollment(): void
    {
        $customer = Customer::factory()->create();
        $contact = Contact::factory()->forCustomer($customer)->create([
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@example.test',
            'phone' => '+51 999 111 222',
        ]);
        $edition = CourseEdition::factory()->create();
        $service = app(CourseEnrollmentService::class);

        $enrollment = $service->enroll($edition, ['contact_id' => $contact->id]);

        $this->assertSame($contact->id, $enrollment->participant->contact_id);
        $this->assertSame($customer->id, $enrollment->participant->customer_id);
        $this->assertSame('Ana', $enrollment->participant->first_name);
        $this->assertDatabaseHas('course_enrollments', [
            'course_edition_id' => $edition->id,
            'course_participant_id' => $enrollment->participant->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already enrolled');
        $service->enroll($edition, ['contact_id' => $contact->id]);
    }

    public function test_it_reuses_a_minimum_participant_by_normalized_document_for_another_edition(): void
    {
        $participant = [
            'first_name' => 'Luz', 'last_name' => 'Ramos', 'document_type' => 'dni', 'document_number' => '12-345-678', 'email' => 'LUZ@example.test', 'mobile' => '+51 999 111 222',
        ];
        $first = app(CourseEnrollmentService::class)->enroll(CourseEdition::factory()->create(), $participant);
        $second = app(CourseEnrollmentService::class)->enroll(CourseEdition::factory()->create(), array_merge($participant, ['document_number' => '12345678']));

        $this->assertSame($first->participant->id, $second->participant->id);
        $this->assertSame('12345678', $second->participant->document_number_norm);
        $this->assertDatabaseCount('course_participants', 1);
    }

    public function test_it_changes_payment_only_through_permitted_transitions_and_requests_eligibility_after_commit(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $enrollment = CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Pending]);
        $service = app(CourseEnrollmentService::class);

        $updated = $service->changePaymentStatus($enrollment, PaymentStatus::Partial);
        $updated = $service->changePaymentStatus($updated, PaymentStatus::Paid);

        $this->assertSame(PaymentStatus::Paid, $updated->payment_status);
        Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
            $event->enrollmentId === $enrollment->id && $event->reason === 'payment'
        );
        Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
            $job->enrollmentId === $enrollment->id && $job->reason === 'payment' && $job->afterCommit === true
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $service->changePaymentStatus($updated, PaymentStatus::Partial);
    }

    public function test_it_uses_the_currently_locked_payment_state_instead_of_a_stale_model(): void
    {
        $enrollment = CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Pending]);
        $staleEnrollment = CourseEnrollment::findOrFail($enrollment->id);
        DB::table('course_enrollments')->where('id', $enrollment->id)->update(['payment_status' => PaymentStatus::Paid->value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('paid to partial is not permitted');

        app(CourseEnrollmentService::class)->changePaymentStatus($staleEnrollment, PaymentStatus::Partial);
    }

    public function test_it_allows_the_remaining_valid_payment_transitions(): void
    {
        $service = app(CourseEnrollmentService::class);

        $this->assertSame(PaymentStatus::Paid, $service->changePaymentStatus(
            CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Pending]),
            PaymentStatus::Paid,
        )->payment_status);
        $this->assertSame(PaymentStatus::Waived, $service->changePaymentStatus(
            CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Partial]),
            PaymentStatus::Waived,
            true,
        )->payment_status);
        $this->assertSame(PaymentStatus::Refunded, $service->changePaymentStatus(
            CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Paid]),
            PaymentStatus::Refunded,
        )->payment_status);
    }

    public function test_it_rejects_reversal_and_terminal_payment_transitions(): void
    {
        $service = app(CourseEnrollmentService::class);
        $paid = CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Paid]);
        $refunded = CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Refunded]);

        foreach ([[$paid, PaymentStatus::Partial], [$refunded, PaymentStatus::Paid]] as [$enrollment, $target]) {
            try {
                $service->changePaymentStatus($enrollment, $target);
                $this->fail('Expected reversal or terminal transition to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not permitted', $exception->getMessage());
            }
        }
    }

    public function test_it_does_not_emit_eligibility_requests_when_an_enclosing_transaction_rolls_back(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $enrollment = CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Pending]);

        DB::beginTransaction();
        try {
            app(CourseEnrollmentService::class)->changePaymentStatus($enrollment, PaymentStatus::Paid);
            Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
            Queue::assertNotPushed(EvaluateCourseDocumentEligibility::class);
        } finally {
            DB::rollBack();
        }

        $this->assertSame(PaymentStatus::Pending, $enrollment->fresh()->payment_status);
        Event::assertNotDispatched(CourseEligibilityEvaluationRequested::class);
        Queue::assertNotPushed(EvaluateCourseDocumentEligibility::class);
    }

    public function test_it_requires_explicit_authorization_for_waived_payment_status(): void
    {
        $enrollment = CourseEnrollment::factory()->create(['payment_status' => PaymentStatus::Pending]);
        $service = app(CourseEnrollmentService::class);

        try {
            $service->changePaymentStatus($enrollment, PaymentStatus::Waived);
            $this->fail('Expected an unauthorized waiver to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('authorization', $exception->getMessage());
        }

        $updated = $service->changePaymentStatus($enrollment, PaymentStatus::Waived, true);

        $this->assertSame(PaymentStatus::Waived, $updated->payment_status);
    }

    public function test_it_creates_minimum_participants_with_separate_academic_records_under_one_group_payer(): void
    {
        $edition = CourseEdition::factory()->create();

        $enrollments = app(CourseEnrollmentService::class)->enrollGroup($edition, [
            'payer_name' => 'Maia Empresa S.A.C.',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
        ], [
            ['first_name' => 'Luz', 'last_name' => 'Ramos', 'document_type' => 'dni', 'document_number' => '12345678', 'email' => 'luz@example.test', 'mobile' => '+51 999 111 222'],
            ['first_name' => 'Marco', 'last_name' => 'Diaz', 'document_type' => 'dni', 'document_number' => '87654321', 'email' => 'marco@example.test', 'mobile' => '+51 999 333 444'],
        ]);

        $this->assertCount(2, $enrollments);
        $this->assertNotSame($enrollments[0]->participant->id, $enrollments[1]->participant->id);
        $this->assertSame($enrollments[0]->course_enrollment_group_id, $enrollments[1]->course_enrollment_group_id);
        $this->assertDatabaseCount('course_enrollment_groups', 1);
        $this->assertDatabaseCount('course_enrollments', 2);
        $this->assertSame(2, CourseEnrollment::where('course_edition_id', $edition->id)->count());
    }
}
