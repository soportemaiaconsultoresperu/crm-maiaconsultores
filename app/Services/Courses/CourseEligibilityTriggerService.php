<?php

namespace App\Services\Courses;

use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Models\Courses\CourseEnrollment;

final class CourseEligibilityTriggerService
{
    public function paymentChanged(CourseEnrollment $enrollment): void
    {
        $this->requestEvaluation($enrollment, 'payment');
    }

    public function gradeChanged(CourseEnrollment $enrollment): void
    {
        $this->requestEvaluation($enrollment, 'grade');
    }

    public function participationChanged(CourseEnrollment $enrollment): void
    {
        $this->requestEvaluation($enrollment, 'participation');
    }

    public function editionValidationChanged(CourseEnrollment $enrollment): void
    {
        $this->requestEvaluation($enrollment, 'edition_validation');
    }

    private function requestEvaluation(CourseEnrollment $enrollment, string $reason): void
    {
        $enrollmentId = (int) $enrollment->getKey();

        CourseEligibilityEvaluationRequested::dispatch($enrollmentId, $reason);
        \App\Jobs\Courses\EvaluateCourseDocumentEligibility::dispatch($enrollmentId, $reason)->afterCommit();
    }
}
