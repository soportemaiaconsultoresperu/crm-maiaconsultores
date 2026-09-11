<?php

namespace App\Policies\Courses;

use App\Models\User;

class CourseEnrollmentPolicy
{
    public function create(User $user): bool
    {
        return $user->can('course-talks.participants.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('course-talks.participants.manage');
    }
}
