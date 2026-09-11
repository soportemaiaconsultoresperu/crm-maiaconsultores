<?php

namespace App\Services\Courses;

use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseEnrollment;
use App\ValueObjects\Courses\CourseEligibilityResult;

final class CourseEligibilityService
{
    public function evaluate(CourseEnrollment $enrollment): CourseEligibilityResult
    {
        $enrollment->loadMissing('edition.activity', 'participant');

        $missing = [];
        $activity = $enrollment->edition->activity;

        if (in_array($enrollment->state, [CourseEnrollmentState::Withdrawn, CourseEnrollmentState::NoShow], true)) {
            return new CourseEligibilityResult(false, ['enrollment_state'], null);
        }

        if (! in_array($enrollment->payment_status, [PaymentStatus::Paid, PaymentStatus::Waived], true)) {
            $missing[] = 'payment';
        }

        if (! $this->hasCompleteParticipantData($enrollment)) {
            $missing[] = 'participant_data';
        }

        if ($enrollment->edition->validations_completed_at === null) {
            $missing[] = 'edition_validations';
        }

        $documentType = null;

        if ($activity->type === CourseActivityType::Talk) {
            if ($activity->talk_includes_certificate && $enrollment->participation_confirmed_at !== null) {
                $documentType = AcademicDocumentType::TalkCertificate;
            } else {
                $missing[] = 'participation';
            }
        } elseif ($enrollment->final_result === FinalResult::Approved) {
            $documentType = AcademicDocumentType::ApprovalCertificate;
        } elseif ($enrollment->final_result === FinalResult::Participation) {
            $documentType = AcademicDocumentType::ParticipationConstancy;
        } else {
            $missing[] = 'academic_result';
        }

        if ($missing !== []) {
            return new CourseEligibilityResult(false, $missing, null);
        }

        return new CourseEligibilityResult(true, [], $documentType);
    }

    private function hasCompleteParticipantData(CourseEnrollment $enrollment): bool
    {
        $participant = $enrollment->participant;

        foreach (['first_name', 'last_name', 'document_type', 'document_number', 'email', 'mobile'] as $field) {
            if (trim((string) $participant->{$field}) === '') {
                return false;
            }
        }

        return true;
    }
}
