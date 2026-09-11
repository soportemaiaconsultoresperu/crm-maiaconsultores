<?php

namespace App\Services\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseGrade;
use App\Models\Courses\CourseSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CourseGradeService
{
    public function record(
        CourseSession $session,
        CourseEnrollment $enrollment,
        string|int $grade,
        ?string $description = null,
        ?User $actor = null,
    ): CourseGrade {
        $courseGrade = DB::transaction(function () use ($session, $enrollment, $grade, $description, $actor): CourseGrade {
            $session->loadMissing('edition.activity');
            $enrollment->loadMissing('edition.activity');

            if ($session->course_edition_id !== $enrollment->course_edition_id) {
                throw new InvalidArgumentException('Session and enrollment must belong to the same edition.');
            }

            if ($enrollment->edition->activity->type === CourseActivityType::Talk) {
                throw new InvalidArgumentException('Talk editions do not accept grades.');
            }

            CourseGradeCalculator::calculate([$grade]);

            $courseGrade = CourseGrade::query()->updateOrCreate(
                [
                    'course_session_id' => $session->id,
                    'course_enrollment_id' => $enrollment->id,
                ],
                [
                    'description' => $description,
                    'grade' => $this->formatGrade($grade),
                    'entered_by' => $actor?->id,
                    'entered_at' => now(),
                ],
            );

            $this->recalculateEnrollmentResult($enrollment);

            return $courseGrade->refresh();
        });

        DB::afterCommit(fn () => app(CourseEligibilityTriggerService::class)->gradeChanged($enrollment->fresh()));

        return $courseGrade;
    }

    private function recalculateEnrollmentResult(CourseEnrollment $enrollment): void
    {
        $grades = CourseGrade::query()
            ->where('course_enrollment_id', $enrollment->id)
            ->orderBy('course_session_id')
            ->pluck('grade')
            ->all();

        $result = CourseGradeCalculator::calculate($grades);

        $enrollment->forceFill([
            'exact_average' => $result->exactAverage,
            'display_average' => $result->displayAverage,
            'rounded_result' => $result->roundedResult,
            'final_result' => $result->finalResult,
            'result_calculated_at' => now(),
        ])->save();
    }

    private function formatGrade(string|int $grade): string
    {
        $normalized = trim((string) $grade);
        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((string) (int) $whole) . '.' . str_pad($decimal, 2, '0');
    }
}
