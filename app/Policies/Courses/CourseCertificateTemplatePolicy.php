<?php

namespace App\Policies\Courses;

use App\Models\User;

class CourseCertificateTemplatePolicy
{
    public function manage(User $user): bool
    {
        return $user->can('course-talks.templates.manage');
    }
}
