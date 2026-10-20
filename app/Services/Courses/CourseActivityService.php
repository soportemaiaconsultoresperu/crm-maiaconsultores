<?php

namespace App\Services\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Events\Courses\CourseActivityChanged;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseActivityService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): CourseActivity
    {
        // The academic hours are validated BEFORE normalize(), because normalize()
        // used to substitute '0.00' for a missing value. That default is what made this
        // defect silent instead of loud: leaving the field blank did not fail, it
        // created a course with ZERO academic hours, and those hours are printed on the
        // student's certificate. A rule that runs after the substitution can never see
        // the missing value, so it has to run before it.
        $this->validateHours($attributes['official_academic_hours'] ?? null);

        $attributes = $this->normalize($attributes);

        $this->ensureCodeIsFree($attributes['code']);

        return DB::transaction(function () use ($attributes): CourseActivity {
            $activity = CourseActivity::create($attributes);

            CourseActivityChanged::dispatch($activity->id, 'course-activity-created');

            return $activity;
        });
    }

    /**
     * Edit an activity with the same rules as create(). The code stays unique among
     * every other activity (deleted ones included, exactly like create()), and the
     * TYPE is frozen once the activity has dictados: a course and a talk charge,
     * grade and certify differently, so turning one into the other under existing
     * deliveries would rewrite what those deliveries already sold and issued.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(CourseActivity $activity, array $attributes): CourseActivity
    {
        $this->validateHours($attributes['official_academic_hours'] ?? null);

        // The stored slug is the activity's identity; an edit never regenerates it.
        $attributes['slug'] = $activity->slug;
        $attributes = $this->normalize($attributes);

        $this->ensureCodeIsFree($attributes['code'], $activity);

        if ($attributes['type'] !== $activity->type->value && $activity->editions()->exists()) {
            throw InvalidCourseEditionData::forField('type', 'No se puede cambiar el tipo porque el curso o charla ya tiene dictados.');
        }

        // Whether a talk issues certificates decides charges and document issuing;
        // once people are enrolled it is frozen, like the type.
        if ((bool) ($attributes['talk_includes_certificate'] ?? false) !== (bool) $activity->talk_includes_certificate
            && \App\Models\Courses\CourseEnrollment::query()
                ->whereIn('course_edition_id', $activity->editions()->withTrashed()->select('id'))
                ->exists()) {
            throw InvalidCourseEditionData::forField('talk_includes_certificate', 'No se puede cambiar «Charla con certificado» porque ya hay participantes inscritos en sus dictados.');
        }

        return DB::transaction(function () use ($activity, $attributes): CourseActivity {
            $activity->fill($attributes)->save();

            CourseActivityChanged::dispatch($activity->id, 'course-activity-updated');

            return $activity;
        });
    }

    /**
     * Soft-delete an activity, but only when nothing hangs off it: a dictado
     * carries enrollments, documents and money, so an activity with dictados is
     * refused instead of orphaning them.
     */
    public function delete(CourseActivity $activity): void
    {
        DB::transaction(function () use ($activity): void {
            // Lock and re-check: CourseEditionService::create() takes the same lock.
            $locked = CourseActivity::query()->lockForUpdate()->findOrFail($activity->getKey());

            if ($locked->editions()->exists()) {
                throw new InvalidCourseEditionData('No se puede eliminar el curso o charla porque tiene dictados registrados. Elimine primero sus dictados.');
            }

            $locked->delete();

            CourseActivityChanged::dispatch($locked->id, 'course-activity-deleted');
        });
    }

    private function validateHours(mixed $hours): void
    {
        if ($hours === null || $hours === '' || ! is_numeric($hours)) {
            throw InvalidCourseEditionData::forField('official_academic_hours', 'Las horas académicas son obligatorias.');
        }

        if ((float) $hours < 0) {
            throw InvalidCourseEditionData::forField('official_academic_hours', 'Las horas académicas no pueden ser negativas.');
        }
    }

    private function ensureCodeIsFree(string $code, ?CourseActivity $except = null): void
    {
        $taken = CourseActivity::withTrashed()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw InvalidCourseEditionData::forField('code', "El código «{$code}» ya está en uso por otro curso o charla.");
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $code = trim((string) ($attributes['code'] ?? ''));
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($code === '' || $name === '') {
            throw new InvalidCourseEditionData('El código y el nombre son obligatorios.');
        }

        $type = $attributes['type'] ?? null;
        $type = $type instanceof CourseActivityType ? $type : CourseActivityType::from((string) $type);
        $attributes['type'] = $type->value;
        $attributes['code'] = $code;
        $attributes['name'] = $name;
        $attributes['slug'] = $attributes['slug'] ?? Str::slug($name);
        // Deliberately NO default for `official_academic_hours`: it is required, and a
        // silent '0.00' here is what produced certificates reading "0 horas académicas".
        $attributes['base_syllabus_json'] = $attributes['base_syllabus_json'] ?? [];
        // A course does not issue a per-activity certificate, so a course cannot
        // carry the talk certificate flag. The form hides the field for a course,
        // but hiding is presentation: the PAYLOAD is what reaches the column, and a
        // payload can be built by anything — a stale browser tab, curl, or a future
        // caller of this service. The rule therefore lives here, where every caller
        // passes through, and it is a rejection of course data rather than a blanket
        // wipe: a talk keeps exactly what the operator declared.
        //
        // The certificate PRICE is no longer a template property at all: it moved
        // to the delivery (`course_editions.certificate_charge_amount`), because the
        // same talk is sold again at a different price. This service therefore owns
        // no price for it, and `CourseEdition::certificateCharge()` is the one place
        // a delivery's charge is resolved.
        if ($type === CourseActivityType::Course) {
            $attributes['talk_includes_certificate'] = false;
        } else {
            $attributes['talk_includes_certificate'] = $attributes['talk_includes_certificate'] ?? false;
        }
        $attributes['is_active'] = $attributes['is_active'] ?? true;

        return $attributes;
    }
}
