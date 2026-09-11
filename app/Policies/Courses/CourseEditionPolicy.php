<?php

namespace App\Policies\Courses;

use App\Models\Courses\CourseEdition;
use App\Models\User;

class CourseEditionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('course-talks.view');
    }

    public function view(User $user, CourseEdition $edition): bool
    {
        return $user->can('course-talks.view')
            || $edition->responsible_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('course-talks.editions.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('course-talks.editions.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('course-talks.editions.manage');
    }

    public function manageSessions(User $user): bool
    {
        return $user->can('course-talks.sessions.manage');
    }

    public function manageAttendance(User $user): bool
    {
        return $user->can('course-talks.attendance.manage');
    }

    public function manageGrades(User $user): bool
    {
        return $user->can('course-talks.grades.manage');
    }
}
