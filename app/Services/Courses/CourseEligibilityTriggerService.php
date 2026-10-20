<?php

namespace App\Services\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Models\Courses\CourseActivity;
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

    /**
     * Whether finishing an edition of this activity can be the last condition of
     * an academic document: every course, and a talk created "con certificado".
     * A talk without certificate issues nothing, so its completion requests no
     * evaluation. The edition finish flow asks this instead of hard-coding courses.
     */
    public function editionCompletionCanIssueDocuments(CourseActivity $activity): bool
    {
        return $activity->type === CourseActivityType::Course
            || ($activity->type === CourseActivityType::Talk && (bool) $activity->talk_includes_certificate);
    }

    private function requestEvaluation(CourseEnrollment $enrollment, string $reason): void
    {
        $enrollmentId = (int) $enrollment->getKey();

        CourseEligibilityEvaluationRequested::dispatch($enrollmentId, $reason);
        \App\Jobs\Courses\EvaluateCourseDocumentEligibility::dispatch($enrollmentId, $reason)->afterCommit();
    }
}
