<?php

namespace App\Services\Courses;

use App\Enums\Courses\PaymentStatus;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Contact;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use Illuminate\Support\Facades\DB;

class CourseEnrollmentService
{
    /**
     * @param array<string, mixed> $participantData
     * @param array<string, mixed> $enrollmentData
     */
    public function enroll(CourseEdition $edition, array $participantData, array $enrollmentData = [], ?CourseEnrollmentGroup $group = null): CourseEnrollment
    {
        return DB::transaction(function () use ($edition, $participantData, $enrollmentData, $group): CourseEnrollment {
            $participant = $this->resolveParticipant($participantData);

            if (CourseEnrollment::where('course_edition_id', $edition->id)
                ->where('course_participant_id', $participant->id)
                ->exists()) {
                throw new InvalidCourseEditionData('This participant is already enrolled in the selected edition.');
            }

            $certificateCharge = $edition->certificateCharge();

            $attributes = array_merge([
                'course_edition_id' => $edition->id,
                'course_participant_id' => $participant->id,
                'course_enrollment_group_id' => $group?->id,
                'activity_price_amount' => $edition->price_amount,
                'certificate_charge_amount' => $certificateCharge,
                'discount_amount' => '0.00',
                'currency' => $edition->currency,
            ], $enrollmentData);

            // The certificate charge belongs to the DELIVERY, not to the caller: it
            // is resolved from the edition (and is '0.00' when the activity is not a
            // talk that includes a certificate), so no payload can charge a certificate
            // a course does not issue. The subtotal is then DERIVED from the values this
            // enrollment actually carries — activity price + certificate charge -
            // discount — which is the exact arithmetic the commercial document taxes,
            // so the operator's total and the invoice's total are the same number by
            // construction instead of by coincidence.
            $attributes['certificate_charge_amount'] = $certificateCharge;
            $attributes['subtotal_amount'] = $this->subtotalAmount(
                (string) $attributes['activity_price_amount'],
                $certificateCharge,
                (string) $attributes['discount_amount'],
            );

            return CourseEnrollment::create($attributes)->load('participant');
        });
    }

    /**
     * The enrollment's subtotal: activity price + certificate charge - discount,
     * defined exactly as `CourseCommercialDocumentService::calculateCharges()`
     * defines the amount it taxes.
     *
     * The ARITHMETIC is not here: it lives once, in `CourseEdition::sumMoney()` and
     * `CourseEdition::subtractMoney()` — the same helpers
     * `CourseEdition::enrollmentMoney()` renders the delivery screen from. That is
     * what makes the total the screen shows and the subtotal persisted here the same
     * number for the same delivery, instead of two implementations that happen to
     * agree until one of them is edited. A negative subtotal is still refused rather
     * than stored, mirroring the commercial service's own non-negative rule.
     */
    private function subtotalAmount(string $activityPrice, string $certificateCharge, string $discount): string
    {
        return CourseEdition::subtractMoney(
            CourseEdition::sumMoney($activityPrice, $certificateCharge),
            $discount,
        );
    }

    public function changePaymentStatus(
        CourseEnrollment $enrollment,
        PaymentStatus $status,
        bool $waiverAuthorized = false,
    ): CourseEnrollment {
        $updated = DB::transaction(function () use ($enrollment, $status, $waiverAuthorized): CourseEnrollment {
            $lockedEnrollment = CourseEnrollment::query()
                ->lockForUpdate()
                ->findOrFail($enrollment->getKey());
            $previous = $lockedEnrollment->payment_status;

            if ($previous === $status) {
                return $lockedEnrollment;
            }

            if ($status === PaymentStatus::Waived && ! $waiverAuthorized) {
                throw new InvalidCourseEditionData('Payment waiver requires explicit authorization.');
            }

            if (! in_array($status, $this->paymentTransitions()[$previous->value] ?? [], true)) {
                throw new InvalidCourseEditionData("Payment transition from {$previous->value} to {$status->value} is not permitted.");
            }

            $lockedEnrollment->forceFill(['payment_status' => $status])->save();

            return $lockedEnrollment->refresh();
        });

        DB::afterCommit(fn () => app(CourseEligibilityTriggerService::class)->paymentChanged($updated));

        return $updated;
    }

    /** @return array<string, array<int, PaymentStatus>> */
    private function paymentTransitions(): array
    {
        return [
            PaymentStatus::Pending->value => [PaymentStatus::Partial, PaymentStatus::Paid, PaymentStatus::Waived],
            PaymentStatus::Partial->value => [PaymentStatus::Paid, PaymentStatus::Waived],
            PaymentStatus::Paid->value => [PaymentStatus::Refunded],
            PaymentStatus::Waived->value => [],
            PaymentStatus::Refunded->value => [],
        ];
    }

    /**
     * @param array<string, mixed> $payerData
     * @param array<int, array<string, mixed>> $participants
     * @return array<int, CourseEnrollment>
     */
    public function enrollGroup(CourseEdition $edition, array $payerData, array $participants): array
    {
        if ($participants === []) {
            throw new InvalidCourseEditionData('A group enrollment requires at least one participant.');
        }

        return DB::transaction(function () use ($edition, $payerData, $participants): array {
            $group = CourseEnrollmentGroup::create([
                'course_edition_id' => $edition->id,
                'payer_customer_id' => $payerData['payer_customer_id'] ?? null,
                'payer_name' => trim((string) ($payerData['payer_name'] ?? '')),
                'payer_document_type' => $payerData['payer_document_type'] ?? null,
                'payer_document_number' => $payerData['payer_document_number'] ?? null,
                'notes' => $payerData['notes'] ?? null,
            ]);

            if ($group->payer_name === '') {
                throw new InvalidCourseEditionData('A group payer name is required.');
            }

            return array_map(fn (array $data): CourseEnrollment => $this->enroll($edition, $data, [], $group), $participants);
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveParticipant(array $data): CourseParticipant
    {
        if (isset($data['course_participant_id'])) {
            return CourseParticipant::findOrFail($data['course_participant_id']);
        }

        if (isset($data['contact_id'])) {
            $contact = Contact::findOrFail($data['contact_id']);

            return CourseParticipant::firstOrCreate(['contact_id' => $contact->id], $this->contactAttributes($contact));
        }

        $attributes = $this->minimumAttributes($data);

        return CourseParticipant::firstOrCreate([
            'document_type' => $attributes['document_type'],
            'document_number_norm' => $attributes['document_number_norm'],
        ], $attributes);
    }

    /** @return array<string, mixed> */
    private function contactAttributes(Contact $contact): array
    {
        return $this->minimumAttributes([
            'customer_id' => $contact->customer_id,
            'contact_id' => $contact->id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'document_type' => CourseParticipant::SYNTHETIC_DOCUMENT_TYPE,
            'document_number' => 'contact-'.$contact->id,
            'email' => $contact->email,
            'mobile' => $contact->phone ?? $contact->whatsapp,
        ]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function minimumAttributes(array $data): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $documentType = trim((string) ($data['document_type'] ?? ''));
        $documentNumber = trim((string) ($data['document_number'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $mobile = trim((string) ($data['mobile'] ?? ''));

        if ($firstName === '' || $lastName === '' || $documentType === '' || $documentNumber === '' || $email === '' || ! str_starts_with($mobile, '+')) {
            throw new InvalidCourseEditionData('Participants require names, document type and number, email, and a mobile number with country code.');
        }

        return [
            'customer_id' => $data['customer_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'document_number_norm' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $documentNumber)),
            'email' => $email,
            'email_norm' => $email,
            'mobile' => $mobile,
            'mobile_norm' => preg_replace('/\D/', '', $mobile),
        ];
    }
}
