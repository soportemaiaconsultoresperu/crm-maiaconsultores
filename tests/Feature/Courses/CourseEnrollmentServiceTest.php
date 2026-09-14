<?php

namespace Tests\Feature\Courses;

use App\Models\Contact;
use App\Models\Customer;
use App\Enums\Courses\CommercialDocumentType;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\PaymentStatus;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CourseCommercialDocumentService;
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

    public function test_a_talk_enrollment_charges_the_delivery_certificate_and_includes_it_in_the_subtotal(): void
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'talk_includes_certificate' => true,
        ]);
        $edition = CourseEdition::factory()->create([
            'course_activity_id' => $activity->id,
            'price_amount' => '100.00',
            'certificate_charge_amount' => '25.00',
        ]);

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition, [
            'first_name' => 'Ana', 'last_name' => 'Torres', 'document_type' => 'dni',
            'document_number' => '70123456', 'email' => 'ana@example.test', 'mobile' => '+51 999 111 222',
        ]);

        // The delivery charges S/25 for the certificate, so the enrollment charges
        // S/25 too and its subtotal is the sum it claims to be: 100 + 25 - 0.
        $this->assertSame('100.00', $enrollment->activity_price_amount);
        $this->assertSame('25.00', $enrollment->certificate_charge_amount);
        $this->assertSame('125.00', $enrollment->subtotal_amount);
        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'certificate_charge_amount' => '25.00',
            'subtotal_amount' => '125.00',
        ]);
    }

    public function test_a_course_enrollment_never_charges_a_certificate_even_when_the_delivery_row_holds_one(): void
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'talk_includes_certificate' => false,
        ]);
        $edition = CourseEdition::factory()->create([
            'course_activity_id' => $activity->id,
            'price_amount' => '100.00',
        ]);

        // The write guard forces a course's stored charge to 0.00; this raw write
        // bypasses it on purpose, so the test proves the ENROLLMENT resolver also
        // refuses to charge — even a tampered row cannot make a course bill a
        // certificate nobody issues.
        DB::table('course_editions')->where('id', $edition->id)->update(['certificate_charge_amount' => '50.00']);

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition->fresh(), [
            'first_name' => 'Ana', 'last_name' => 'Torres', 'document_type' => 'dni',
            'document_number' => '70123457', 'email' => 'ana.course@example.test', 'mobile' => '+51 999 111 222',
        ]);

        $this->assertSame('0.00', $enrollment->certificate_charge_amount);
        $this->assertSame('100.00', $enrollment->subtotal_amount);
    }

    public function test_a_talk_without_a_certificate_never_charges_one_even_when_the_delivery_row_holds_one(): void
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'talk_includes_certificate' => false,
        ]);
        $edition = CourseEdition::factory()->create([
            'course_activity_id' => $activity->id,
            'price_amount' => '100.00',
        ]);

        DB::table('course_editions')->where('id', $edition->id)->update(['certificate_charge_amount' => '50.00']);

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition->fresh(), [
            'first_name' => 'Luz', 'last_name' => 'Ramos', 'document_type' => 'dni',
            'document_number' => '70123458', 'email' => 'luz.talk@example.test', 'mobile' => '+51 999 111 222',
        ]);

        $this->assertSame('0.00', $enrollment->certificate_charge_amount);
        $this->assertSame('100.00', $enrollment->subtotal_amount);
    }

    public function test_a_course_edition_cannot_store_a_certificate_charge(): void
    {
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);

        // The operator (or a future form, or curl) hands a course delivery a
        // certificate charge. A course does not issue a talk certificate, so the
        // domain refuses to keep it — the same invariant the activity already holds.
        $edition = CourseEdition::factory()->create([
            'course_activity_id' => $activity->id,
            'certificate_charge_amount' => '50.00',
        ]);

        $this->assertSame('0.00', $edition->fresh()->certificate_charge_amount);
        $this->assertDatabaseHas('course_editions', [
            'id' => $edition->id,
            'certificate_charge_amount' => '0.00',
        ]);
    }

    public function test_a_talk_edition_keeps_the_certificate_charge_the_operator_declared(): void
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'talk_includes_certificate' => true,
        ]);

        $edition = CourseEdition::factory()->create([
            'course_activity_id' => $activity->id,
            'certificate_charge_amount' => '25.00',
        ]);

        // The negative control: the invariant is a rejection of course data, not a
        // blanket wipe, so a talk keeps exactly what the operator declared.
        $this->assertSame('25.00', $edition->fresh()->certificate_charge_amount);
    }

    public function test_the_enrollment_subtotal_is_the_subtotal_the_commercial_document_taxes(): void
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'talk_includes_certificate' => true,
        ]);
        $edition = CourseEdition::factory()->create([
            'course_activity_id' => $activity->id,
            'price_amount' => '100.00',
            'certificate_charge_amount' => '25.00',
        ]);

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition, [
            'first_name' => 'Marco', 'last_name' => 'Diaz', 'document_type' => 'dni',
            'document_number' => '70123459', 'email' => 'marco@example.test', 'mobile' => '+51 999 333 444',
        ]);

        // The total the operator sees and the total the invoice uses come from the
        // SAME service arithmetic applied to the SAME persisted charges, so they
        // cannot disagree: 100 + 25 - 0 = 125 taxable, +18% IGV = 147.50.
        $charges = app(CourseCommercialDocumentService::class)->calculateCharges(
            CommercialDocumentType::Boleta,
            (string) $enrollment->activity_price_amount,
            (string) $enrollment->certificate_charge_amount,
            (string) $enrollment->discount_amount,
        );

        $this->assertSame('125.00', $enrollment->subtotal_amount);
        $this->assertSame($enrollment->subtotal_amount, $charges['subtotal_amount']);
        $this->assertSame('147.50', $charges['total_amount']);
    }

    public function test_it_refuses_a_negative_enrollment_subtotal_instead_of_persisting_it(): void
    {
        $edition = CourseEdition::factory()->create(['price_amount' => '100.00']);

        $this->expectException(InvalidCourseEditionData::class);
        $this->expectExceptionMessage('no puede ser negativo');

        app(CourseEnrollmentService::class)->enroll($edition, [
            'first_name' => 'Sara', 'last_name' => 'Paz', 'document_type' => 'dni',
            'document_number' => '70123460', 'email' => 'sara@example.test', 'mobile' => '+51 999 555 666',
        ], ['discount_amount' => '200.00']);
    }

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
