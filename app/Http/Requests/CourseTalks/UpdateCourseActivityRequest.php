<?php

namespace App\Http\Requests\CourseTalks;

/**
 * Editing an activity validates exactly what creating one does, under the same
 * permission; the edit-only rules (a code unique among the OTHER activities, a
 * type frozen once the activity has dictados) belong to
 * CourseActivityService::update().
 */
class UpdateCourseActivityRequest extends StoreCourseActivityRequest
{
}
