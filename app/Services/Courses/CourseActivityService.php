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
        $attributes['type'] = $type instanceof CourseActivityType ? $type->value : CourseActivityType::from((string) $type)->value;
        $attributes['code'] = $code;
        $attributes['name'] = $name;
        $attributes['slug'] = $attributes['slug'] ?? Str::slug($name);
        // Deliberately NO default for `official_academic_hours`: it is required, and a
        // silent '0.00' here is what produced certificates reading "0 horas académicas".
        $attributes['base_syllabus_json'] = $attributes['base_syllabus_json'] ?? [];
        $attributes['talk_includes_certificate'] = $attributes['talk_includes_certificate'] ?? false;
        $attributes['talk_certificate_price'] = $attributes['talk_certificate_price'] ?? '0.00';
        $attributes['is_active'] = $attributes['is_active'] ?? true;

        return $attributes;
    }
}
