<?php

namespace App\Services\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseModality;
use App\Events\Courses\CourseEditionChanged;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Exceptions\Courses\InvalidCourseEditionTransition;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEditionTeacher;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseSession;
use Illuminate\Support\Facades\DB;

class CourseEditionService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(CourseActivity $activity, array $attributes): CourseEdition
    {
        $location = $this->validateModality($attributes['modality'] ?? '', $attributes['address'] ?? null, $attributes['access_url'] ?? null);
        $code = trim((string) ($attributes['code'] ?? ''));

        if ($code !== '' && CourseEdition::withTrashed()->where('code', $code)->exists()) {
            throw InvalidCourseEditionData::forField('code', "Course edition code {$code} is already in use.");
        }

        return DB::transaction(function () use ($activity, $attributes, $location, $code): CourseEdition {
            $edition = CourseEdition::create(array_merge($attributes, $location, [
                'course_activity_id' => $activity->id,
                'code' => $code === '' ? null : $code,
                'state' => CourseEditionState::Draft->value,
                'currency' => $attributes['currency'] ?? 'PEN',
                'delivery_due_days' => $attributes['delivery_due_days'] ?? config('courses.delivery_due_days', 1),
            ]));

            CourseEditionChanged::dispatch($edition->id, 'course-edition-created');

            return $edition;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $teachers
     */
    public function syncTeachers(CourseEdition $edition, array $teachers): void
    {
        DB::transaction(function () use ($edition, $teachers): void {
            $edition->teachers()->delete();

            foreach (array_values($teachers) as $index => $teacher) {
                $name = trim((string) ($teacher['display_name'] ?? ''));
                if ($name === '') {
                    throw new InvalidCourseEditionData('Edition teachers require a display name.');
                }

                CourseEditionTeacher::create([
                    'course_edition_id' => $edition->id,
                    'user_id' => $teacher['user_id'] ?? null,
                    'display_name' => $name,
                    'email' => ($email = trim((string) ($teacher['email'] ?? ''))) === '' ? null : $email,
                    'sort_order' => $index + 1,
                ]);
            }

            CourseEditionChanged::dispatch($edition->id, 'course-edition-teachers-changed');
        });
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     */
    public function syncSessions(CourseEdition $edition, array $sessions): void
    {
        DB::transaction(function () use ($edition, $sessions): void {
            foreach (array_values($sessions) as $index => $session) {
                $topic = trim((string) ($session['topic'] ?? ''));
                if ($topic === '') {
                    throw new InvalidCourseEditionData('Course sessions require a topic.');
                }

                $record = CourseSession::withTrashed()->firstOrNew([
                    'course_edition_id' => $edition->id,
                    'sort_order' => $index + 1,
                ]);
                $record->fill([
                    'session_date' => $session['session_date'] ?? null,
                    'starts_at' => $session['starts_at'] ?? null,
                    'ends_at' => $session['ends_at'] ?? null,
                    'teacher_name' => $session['teacher_name'] ?? null,
                    'topic' => $topic,
                ]);
                if ($record->exists && $record->trashed()) {
                    $record->restore();
                } else {
                    $record->save();
                }
            }

            CourseEditionChanged::dispatch($edition->id, 'course-edition-sessions-changed');
        });
    }

    /**
     * @return array<string, string|null>
     */
    public function validateModality(CourseModality|string $modality, ?string $address, ?string $accessUrl): array
    {
        $modality = $modality instanceof CourseModality ? $modality : CourseModality::from($modality);
        $address = trim((string) $address);
        $accessUrl = trim((string) $accessUrl);

        if ($modality === CourseModality::Presential && $address === '') {
            throw InvalidCourseEditionData::forField('address', 'Presential editions require an address.');
        }
        if ($modality === CourseModality::Virtual && $accessUrl === '') {
            throw InvalidCourseEditionData::forField('access_url', 'Virtual editions require an access URL.');
        }
        if ($modality === CourseModality::Hybrid && ($address === '' || $accessUrl === '')) {
            throw InvalidCourseEditionData::forField(
                $address === '' ? 'address' : 'access_url',
                'Hybrid editions require both address and access URL.',
            );
        }

        return ['modality' => $modality->value, 'address' => $address ?: null, 'access_url' => $accessUrl ?: null];
    }

    public function transitionState(CourseEdition $edition, CourseEditionState|string $target): CourseEdition
    {
        $target = $target instanceof CourseEditionState ? $target : CourseEditionState::from($target);
        $current = $edition->state instanceof CourseEditionState ? $edition->state : CourseEditionState::from($edition->state);

        if (! $this->canTransition($current, $target)) {
            throw new InvalidCourseEditionTransition("Cannot transition course edition from {$current->value} to {$target->value}.");
        }

        DB::transaction(function () use ($edition, $target): void {
            $edition->forceFill(['state' => $target])->save();
            CourseEditionChanged::dispatch($edition->id, 'course-edition-state-changed');
        });

        return $edition;
    }

    public function completeValidations(CourseEdition $edition): CourseEdition
    {
        [$completedEdition, $enrollmentIds] = DB::transaction(function () use ($edition): array {
            $lockedEdition = CourseEdition::query()
                ->with('activity')
                ->lockForUpdate()
                ->findOrFail($edition->getKey());

            if ($lockedEdition->validations_completed_at !== null) {
                return [$lockedEdition, []];
            }

            $lockedEdition->forceFill(['validations_completed_at' => now()])->save();
            CourseEditionChanged::dispatch($lockedEdition->id, 'course-edition-validations-completed');

            if ($lockedEdition->activity->type !== CourseActivityType::Course) {
                return [$lockedEdition, []];
            }

            return [$lockedEdition, $lockedEdition->enrollments()->pluck('id')->all()];
        });

        DB::afterCommit(function () use ($enrollmentIds): void {
            $trigger = app(CourseEligibilityTriggerService::class);

            foreach ($enrollmentIds as $enrollmentId) {
                $enrollment = CourseEnrollment::find($enrollmentId);

                if ($enrollment !== null) {
                    $trigger->editionValidationChanged($enrollment);
                }
            }
        });

        return $completedEdition;
    }

    public function canTransition(CourseEditionState $current, CourseEditionState $target): bool
    {
        return in_array($target, self::allowedTargets($current), true);
    }

    /** @return array<int, CourseEditionState> */
    private static function allowedTargets(CourseEditionState $state): array
    {
        return match ($state) {
            CourseEditionState::Draft => [CourseEditionState::Scheduled, CourseEditionState::Cancelled],
            CourseEditionState::Scheduled => [CourseEditionState::InProgress, CourseEditionState::Cancelled],
            CourseEditionState::InProgress => [CourseEditionState::Finished, CourseEditionState::Cancelled],
            CourseEditionState::Finished, CourseEditionState::Cancelled => [],
        };
    }
}
