<?php

namespace App\Models\Courses;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Base for every course model that owns the full audit surface: dirty-only
 * activity entries with old/new values, named after the `course-*` convention,
 * plus the `created_by`/`updated_by` columns.
 *
 * The convention itself lives in courseActivitylogOptions() because one more
 * model needs it and cannot extend this base: `course_attendances` has neither
 * the created_by/updated_by columns nor soft deletes, so a base that uses
 * HasAuditColumns and SoftDeletes cannot be its parent.
 */
abstract class CourseModel extends Model
{
    use HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return self::courseActivitylogOptions();
    }

    /**
     * The single definition of the course audit convention: every course change
     * is logged dirty-only, in full, under a `course-<event>` description.
     */
    public static function courseActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $event): string => 'course-'.$event);
    }
}
