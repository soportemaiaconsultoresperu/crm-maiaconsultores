<?php

namespace App\Policies\Courses;

use App\Models\User;

class CourseCommercialDocumentPolicy
{
    public function manage(User $user): bool
    {
        return $user->can('course-talks.commercial-documents.manage');
    }

    public function send(User $user): bool
    {
        return $user->can('course-talks.documents.send');
    }
}
