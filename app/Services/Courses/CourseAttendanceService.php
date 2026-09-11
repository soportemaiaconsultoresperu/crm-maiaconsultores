<?php

namespace App\Services\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseAttendance;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CourseAttendanceService
{
    /** @var array<int, string> */
    private const VALID_STATUSES = ['present', 'absent', 'late', 'excused', 'unmarked'];

    /** @var array<int, string> */
    private const PARTICIPATING_STATUSES = ['present', 'late', 'excused'];

    public function mark(
        CourseSession $session,
        CourseEnrollment $enrollment,
        string $status,
        ?User $actor = null,
    ): CourseAttendance {
        $status = strtolower(trim($status));

        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidCourseEditionData('Attendance status is invalid.');
        }

        $attendance = DB::transaction(function () use ($session, $enrollment, $status, $actor): CourseAttendance {
            $session->loadMissing('edition.activity');
            $enrollment->loadMissing('edition.activity');

            if ($session->course_edition_id !== $enrollment->course_edition_id) {
                throw new InvalidCourseEditionData('Session and enrollment must belong to the same edition.');
            }

            $attendance = CourseAttendance::query()->updateOrCreate(
                [
                    'course_session_id' => $session->id,
                    'course_enrollment_id' => $enrollment->id,
                ],
                [
                    'status' => $status,
                    'marked_by' => $actor?->id,
                    'marked_at' => now(),
                ],
            );

            if ($enrollment->edition->activity->type === CourseActivityType::Talk) {
                $this->refreshTalkParticipation($enrollment);
            }

            return $attendance->refresh();
        });

        if ($enrollment->fresh()->edition->activity->type === CourseActivityType::Talk) {
            app(CourseEligibilityTriggerService::class)->participationChanged($enrollment->fresh());
        }

        return $attendance;
    }

    private function refreshTalkParticipation(CourseEnrollment $enrollment): void
    {
        $hasParticipation = CourseAttendance::query()
            ->where('course_enrollment_id', $enrollment->id)
            ->whereIn('status', self::PARTICIPATING_STATUSES)
            ->exists();

        $enrollment->forceFill([
            'participation_confirmed_at' => $hasParticipation ? now() : null,
        ])->save();
    }
}
