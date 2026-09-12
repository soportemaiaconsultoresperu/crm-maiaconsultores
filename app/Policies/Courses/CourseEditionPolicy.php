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

    /**
     * Managing the session schedule (classes) of an edition.
     *
     * `course-talks.sessions.manage` is the design's dedicated permission for
     * this surface, so holding it is enough on its own — including for a user
     * who may not edit the edition itself. It is deliberately additive with
     * `course-talks.editions.manage`: every actor that could manage sessions
     * before this rule existed (the seeded admin, the module managers in the
     * navigation fixtures) keeps working, so the wiring grants a capability and
     * revokes none. `attendance.manage` and `grades.manage` stay strict because
     * they never had a coarser gate to widen from.
     */
    public function manageSessions(User $user): bool
    {
        return $user->can('course-talks.sessions.manage')
            || $user->can('course-talks.editions.manage');
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
