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
     * The single "no certificate price" value for a course.
     *
     * Named once because it is asserted as `0.00` by the persisted-row tests and
     * by the operator-facing form, and two literals that drift apart would make a
     * course look like it still holds a price.
     */
    private const NO_TALK_CERTIFICATE_PRICE = '0.00';

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
        $hours = $attributes['official_academic_hours'] ?? null;

        if ($hours === null || $hours === '' || ! is_numeric($hours)) {
        throw InvalidCourseEditionData::forField('official_academic_hours', 'Las horas académicas son obligatorias.');
        }

        if ((float) $hours < 0) {
        throw InvalidCourseEditionData::forField('official_academic_hours', 'Las horas académicas no pueden ser negativas.');
        }

        $attributes = $this->normalize($attributes);

        if (CourseActivity::withTrashed()->where('code', $attributes['code'])->exists()) {
            throw new InvalidCourseEditionData("Course activity code {$attributes['code']} is already in use.");
        }

        return DB::transaction(function () use ($attributes): CourseActivity {
            $activity = CourseActivity::create($attributes);

            CourseActivityChanged::dispatch($activity->id, 'course-activity-created');

            return $activity;
        });
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
            throw new InvalidCourseEditionData('Course activities require a code and name.');
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
        // A course does not issue a per-activity certificate at its own price, so a
        // course cannot carry talk certificate data. The form hides those two fields
        // for a course, but hiding is presentation: the PAYLOAD is what reaches the
        // column, and a payload can be built by anything — a stale browser tab, curl,
        // or a future caller of this service. The rule therefore lives here, where
        // every caller passes through, and it is a rejection of course data rather
        // than a blanket wipe: a talk keeps exactly what the operator declared.
        if ($type === CourseActivityType::Course) {
            $attributes['talk_includes_certificate'] = false;
            $attributes['talk_certificate_price'] = self::NO_TALK_CERTIFICATE_PRICE;
        } else {
            $attributes['talk_includes_certificate'] = $attributes['talk_includes_certificate'] ?? false;
            $attributes['talk_certificate_price'] = $attributes['talk_certificate_price'] ?? self::NO_TALK_CERTIFICATE_PRICE;
        }
        $attributes['is_active'] = $attributes['is_active'] ?? true;

        return $attributes;
    }
}
