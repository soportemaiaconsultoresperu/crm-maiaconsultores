<?php

namespace App\Policies\Courses;

use App\Models\User;

class CourseActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('course-talks.view');
    }

    public function view(User $user): bool
    {
        return $user->can('course-talks.view');
    }

    public function create(User $user): bool
    {
        return $user->can('course-talks.activities.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('course-talks.activities.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('course-talks.activities.manage');
    }

    public function viewAudit(User $user): bool
    {
        return $user->can('course-talks.audit.view');
    }
}
