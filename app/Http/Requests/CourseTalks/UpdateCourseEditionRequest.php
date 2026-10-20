<?php

namespace App\Http\Requests\CourseTalks;

/**
 * Editing a dictado validates exactly what creating one does, under the same
 * permission; the edit-only rule (a code unique among the OTHER dictados)
 * belongs to CourseEditionService::update().
 */
class UpdateCourseEditionRequest extends StoreCourseEditionRequest
{
}
