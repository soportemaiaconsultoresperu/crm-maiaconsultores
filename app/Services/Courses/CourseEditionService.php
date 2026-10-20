<?php

namespace App\Services\Courses;

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
        // The activity's "Activa" flag is what stops new deliveries; the dictados it
        // already has keep working (update() does not ask this).
        self::ensureActivityAcceptsNewEditions($activity);

        $location = $this->validateModality($attributes['modality'] ?? '', $attributes['address'] ?? null, $attributes['access_url'] ?? null);
        $code = trim((string) ($attributes['code'] ?? ''));

        $this->validatePrice($attributes['price_amount'] ?? null);
        $this->ensureCodeIsFree($code);

        // The certificate charge is OPTIONAL DELIVERY MONEY: the create form offers it
        // only for a talk that includes a certificate, and every other delivery is worth
        // '0.00' (the column's own default). A blank value must become '0.00' HERE
        // instead of travelling as an explicit NULL, because the column is NOT NULL and
        // the insert would die as a QueryException — the exact defect `price_amount`
        // had. This is NOT a second copy of "does a certificate apply": that rule
        // belongs to CourseActivity::issuesTalkCertificate() and is enforced on write by
        // CourseEdition's `saving` guard, which zeroes the column whenever the activity
        // is not a certificate talk. This only reads "no amount entered" as the zero it
        // means.
        $attributes['certificate_charge_amount'] = $this->normalizeCertificateCharge(
            $attributes['certificate_charge_amount'] ?? null,
        );

        return DB::transaction(function () use ($activity, $attributes, $location, $code): CourseEdition {
            // Same lock CourseActivityService::delete() takes, so an activity deleted
            // meanwhile never gets a new dictado.
            if (CourseActivity::query()->lockForUpdate()->find($activity->getKey()) === null) {
                throw new InvalidCourseEditionData('El curso o charla ya no existe; actualice la página.');
            }

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
     * Edit a delivery's own data with the same rules as create(). The lifecycle
     * state, currency and delivery-due days are not part of the edit: the state
     * moves only through transitionState(). Enrollments keep the money they were
     * created with, so a new price applies to new enrollments only.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(CourseEdition $edition, array $attributes): CourseEdition
    {
        $location = $this->validateModality($attributes['modality'] ?? '', $attributes['address'] ?? null, $attributes['access_url'] ?? null);
        $code = trim((string) ($attributes['code'] ?? ''));

        $this->validatePrice($attributes['price_amount'] ?? null);
        $this->ensureCodeIsFree($code, $edition);

        $attributes['certificate_charge_amount'] = $this->normalizeCertificateCharge(
            $attributes['certificate_charge_amount'] ?? null,
        );

        $editable = array_intersect_key($attributes, array_flip([
            'starts_on', 'ends_on', 'price_amount', 'certificate_charge_amount', 'syllabus_override_json', 'responsible_user_id',
        ]));

        return DB::transaction(function () use ($edition, $editable, $location, $code): CourseEdition {
            $edition->fill(array_merge($editable, $location, [
                'code' => $code === '' ? null : $code,
            ]))->save();

            CourseEditionChanged::dispatch($edition->id, 'course-edition-updated');

            return $edition;
        });
    }

    /**
     * Soft-delete a delivery, but only when nobody is enrolled in it: an
     * enrollment carries money, attendance, grades and documents, so a delivery
     * with enrollments is refused instead of orphaning them.
     */
    public function delete(CourseEdition $edition): void
    {
        DB::transaction(function () use ($edition): void {
            // Lock the row and re-check under the lock: enroll()/enrollGroup() take
            // the same lock, so no enrollment can slip in between check and delete.
            $locked = self::lockLiveEdition($edition);

            if ($locked->enrollments()->withTrashed()->exists()) {
                throw new InvalidCourseEditionData('No se puede eliminar el dictado porque tiene participantes inscritos.');
            }

            if (\App\Models\Courses\CourseEnrollmentGroup::query()->where('course_edition_id', $locked->id)->exists()) {
                throw new InvalidCourseEditionData('No se puede eliminar el dictado porque tiene inscripciones grupales.');
            }

            $locked->delete();

            CourseEditionChanged::dispatch($locked->id, 'course-edition-deleted');
        });
    }

    /**
     * Lock a dictado that still exists, or refuse: a stale model of a dictado
     * deleted meanwhile must not receive enrollments or groups.
     */
    public static function lockLiveEdition(CourseEdition $edition): CourseEdition
    {
        $locked = CourseEdition::query()->lockForUpdate()->find($edition->getKey());

        if ($locked === null) {
            throw new InvalidCourseEditionData('El dictado ya no existe; actualice la página.');
        }

        return $locked;
    }

    /**
     * The one "may this activity get a new dictado" rule, read by create() and by
     * the create form before it renders.
     */
    public static function ensureActivityAcceptsNewEditions(CourseActivity $activity): void
    {
        if (! $activity->is_active) {
            throw InvalidCourseEditionData::forField('edition', 'No se pueden crear dictados de un curso o charla inactivo. Actívelo primero desde «Editar».');
        }
    }

    /**
     * The price is required by the spec and the column is NOT NULL, so the rule
     * belongs here as well as in the form request: a caller that does not come
     * through HTTP used to reach the database with a null and die as a 500. Zero is
     * allowed — a free edition is still an edition.
     */
    private function validatePrice(mixed $price): void
    {
        if ($price === null || $price === '' || ! is_numeric($price)) {
            throw InvalidCourseEditionData::forField('price_amount', 'El precio del dictado es obligatorio.');
        }

        if ((float) $price < 0) {
            throw InvalidCourseEditionData::forField('price_amount', 'El precio del dictado no puede ser negativo.');
        }
    }

    private function ensureCodeIsFree(string $code, ?CourseEdition $except = null): void
    {
        if ($code === '') {
            return;
        }

        $taken = CourseEdition::withTrashed()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw InvalidCourseEditionData::forField('code', "El código de dictado «{$code}» ya está en uso.");
        }
    }

    /**
     * @param array<int, array<string, mixed>> $teachers
     */
    public function syncTeachers(CourseEdition $edition, array $teachers): void
    {
        DB::transaction(function () use ($edition, $teachers): void {
            $submittedActiveIds = [];
            $position = 1;

            $originalOrder = $edition->teachers()->orderBy('sort_order')->pluck('id')->values()->all();

            DB::table('course_edition_teachers')
                ->where('course_edition_id', $edition->id)
                ->whereNull('deleted_at')
                ->update(['sort_order' => null]);

            foreach (array_values($teachers) as $teacher) {
                $id = $teacher['id'] ?? null;
                $remove = filter_var($teacher['remove'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($remove) {
                    if ($id === null) {
                        continue;
                    }

                    $record = $this->editionTeacherForUpdate($edition, (int) $id);
                    if (CourseSession::query()
                        ->where('course_edition_id', $edition->id)
                        ->where('teacher_id', $record->id)
                        ->exists()) {
                        throw InvalidCourseEditionData::forField('teachers', 'No se puede quitar el docente porque está asignado a una o más sesiones. Reasigne las sesiones antes de quitarlo.');
                    }
                    $record->forceFill(['sort_order' => null])->save();
                    $record->delete();

                    continue;
                }

                $name = trim((string) ($teacher['display_name'] ?? ''));
                if ($name === '') {
                    throw new InvalidCourseEditionData('El nombre del docente es obligatorio.');
                }

                $record = $id === null
                    ? new CourseEditionTeacher(['course_edition_id' => $edition->id])
                    : $this->editionTeacherForUpdate($edition, (int) $id);

                $record->fill([
                    'user_id' => $teacher['user_id'] ?? null,
                    'display_name' => $name,
                    'email' => ($email = trim((string) ($teacher['email'] ?? ''))) === '' ? null : $email,
                    'sort_order' => $position++,
                ]);
                $record->save();
                $submittedActiveIds[] = (int) $record->id;
            }

            $orderIndex = array_flip($originalOrder);
            $omitted = $edition->teachers()
                ->when($submittedActiveIds !== [], fn ($query) => $query->whereNotIn('id', $submittedActiveIds))
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (CourseEditionTeacher $teacher): int => $orderIndex[$teacher->id] ?? PHP_INT_MAX);

            foreach ($omitted as $teacher) {
                $teacher->forceFill(['sort_order' => $position++])->save();
            }

            CourseEditionChanged::dispatch($edition->id, 'course-edition-teachers-changed');
        });
    }

    private function editionTeacherForUpdate(CourseEdition $edition, int $teacherId): CourseEditionTeacher
    {
        $teacher = CourseEditionTeacher::query()
            ->whereKey($teacherId)
            ->where('course_edition_id', $edition->id)
            ->lockForUpdate()
            ->first();

        if ($teacher === null) {
            throw InvalidCourseEditionData::forField('teachers', 'El docente seleccionado no pertenece a este dictado.');
        }

        return $teacher;
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
                    throw new InvalidCourseEditionData('El tema de la sesión es obligatorio.');
                }

                $teacherId = $session['teacher_id'] ?? null;
                $teacherId = $teacherId === '' ? null : $teacherId;
                if ($teacherId !== null) {
                    $teacherId = CourseEditionTeacher::query()
                        ->whereKey((int) $teacherId)
                        ->where('course_edition_id', $edition->id)
                        ->lockForUpdate()
                        ->value('id');

                    if ($teacherId === null) {
                        throw InvalidCourseEditionData::forField('sessions.'.$index.'.teacher_id', 'El docente seleccionado no pertenece a este dictado.');
                    }
                }

                $record = CourseSession::withTrashed()->firstOrNew([
                    'course_edition_id' => $edition->id,
                    'sort_order' => $index + 1,
                ]);
                $record->fill([
                    'session_date' => $session['session_date'] ?? null,
                    'starts_at' => $session['starts_at'] ?? null,
                    'ends_at' => $session['ends_at'] ?? null,
                    'teacher_id' => $teacherId,
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
            throw InvalidCourseEditionData::forField('address', 'Para un dictado presencial, la dirección es obligatoria.');
        }
        if ($modality === CourseModality::Virtual && $accessUrl === '') {
            throw InvalidCourseEditionData::forField('access_url', 'Para un dictado virtual, el enlace de acceso es obligatorio.');
        }
        if ($modality === CourseModality::Hybrid && ($address === '' || $accessUrl === '')) {
            throw InvalidCourseEditionData::forField(
                $address === '' ? 'address' : 'access_url',
                'Para un dictado híbrido, la dirección y el enlace de acceso son obligatorios.',
            );
        }

        return ['modality' => $modality->value, 'address' => $address ?: null, 'access_url' => $accessUrl ?: null];
    }

    /**
     * An absent or blank certificate charge means "this delivery charges nothing for
     * the certificate", which is the column's own default ('0.00'), not a NULL — the
     * column is NOT NULL and an explicit NULL insert raises a QueryException instead of
     * storing the zero. A value that IS present is passed through untouched: whether it
     * may be kept at all is the model's write guard's decision, never this method's.
     */
    private function normalizeCertificateCharge(mixed $charge): string
    {
        if ($charge === null || $charge === '') {
            return '0.00';
        }

        return (string) $charge;
    }

    public function transitionState(CourseEdition $edition, CourseEditionState|string $target): CourseEdition
    {
        $target = $target instanceof CourseEditionState ? $target : CourseEditionState::from($target);
        $current = $edition->state instanceof CourseEditionState ? $edition->state : CourseEditionState::from($edition->state);

        if (! $this->canTransition($current, $target)) {
            throw new InvalidCourseEditionTransition("No se puede cambiar el estado del dictado de «{$current->label()}» a «{$target->label()}».");
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

            if (! app(CourseEligibilityTriggerService::class)->editionCompletionCanIssueDocuments($lockedEdition->activity)) {
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

    /**
     * The states this delivery may move to, from the very table `transitionState()`
     * enforces. A surface that offers state changes reads it from here instead of
     * repeating the rules, so a button can never offer something the service refuses.
     *
     * @return array<int, CourseEditionState>
     */
    public static function allowedTransitions(CourseEdition $edition): array
    {
        $current = $edition->state instanceof CourseEditionState
            ? $edition->state
            : CourseEditionState::from($edition->state);

        return self::allowedTargets($current);
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
