<?php

namespace App\Policies\Courses;

use App\Models\User;

class CourseAcademicDocumentPolicy
{
    public function generate(User $user): bool
    {
        return $user->can('course-talks.documents.generate');
    }

    public function revoke(User $user): bool
    {
        return $user->can('course-talks.documents.revoke');
    }

    public function send(User $user): bool
    {
        return $user->can('course-talks.documents.send');
    }
}
