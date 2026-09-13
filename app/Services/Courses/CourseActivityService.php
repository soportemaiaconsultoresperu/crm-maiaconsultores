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
        $attributes['official_academic_hours'] = $attributes['official_academic_hours'] ?? '0.00';
        $attributes['base_syllabus_json'] = $attributes['base_syllabus_json'] ?? [];
        $attributes['talk_includes_certificate'] = $attributes['talk_includes_certificate'] ?? false;
        $attributes['talk_certificate_price'] = $attributes['talk_certificate_price'] ?? '0.00';
        $attributes['is_active'] = $attributes['is_active'] ?? true;

        return $attributes;
    }
}
