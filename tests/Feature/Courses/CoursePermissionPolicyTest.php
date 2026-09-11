<?php

namespace Tests\Feature\Courses;

use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCertificateTemplate;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CoursePermissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'course-talks.view',
        'course-talks.activities.manage',
        'course-talks.editions.manage',
        'course-talks.sessions.manage',
        'course-talks.attendance.manage',
        'course-talks.grades.manage',
        'course-talks.participants.manage',
        'course-talks.documents.generate',
        'course-talks.documents.revoke',
        'course-talks.commercial-documents.manage',
        'course-talks.documents.send',
        'course-talks.templates.manage',
        'course-talks.audit.view',
    ];

    public function test_course_permissions_are_seeded_and_assignable(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);

        $user->givePermissionTo(self::PERMISSIONS);

        foreach (self::PERMISSIONS as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission,
                'guard_name' => 'web',
            ]);
            $this->assertTrue($user->fresh()->can($permission), "Expected {$permission} to be assignable.");
        }
    }

    public function test_course_policies_deny_restricted_actions_without_granular_permissions(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $edition = CourseEdition::factory()->create(['responsible_user_id' => User::factory()->create()->id]);

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', CourseActivity::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', CourseActivity::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $edition));
        $this->assertFalse(Gate::forUser($user)->allows('manageSessions', $edition));
        $this->assertFalse(Gate::forUser($user)->allows('manageAttendance', $edition));
        $this->assertFalse(Gate::forUser($user)->allows('manageGrades', $edition));
        $this->assertFalse(Gate::forUser($user)->allows('create', CourseEnrollment::class));
        $this->assertFalse(Gate::forUser($user)->allows('generate', CourseAcademicDocument::class));
        $this->assertFalse(Gate::forUser($user)->allows('revoke', CourseAcademicDocument::class));
        $this->assertFalse(Gate::forUser($user)->allows('manage', CourseCommercialDocument::class));
        $this->assertFalse(Gate::forUser($user)->allows('manage', CourseCertificateTemplate::class));
    }

    public function test_course_policies_allow_only_matching_granular_permissions_and_responsible_view(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $responsible = User::factory()->create(['is_active' => true]);
        $manager = User::factory()->create(['is_active' => true]);
        $edition = CourseEdition::factory()->create(['responsible_user_id' => $responsible->id]);

        $responsible->givePermissionTo('course-talks.view');
        $manager->givePermissionTo([
            'course-talks.view',
            'course-talks.activities.manage',
            'course-talks.editions.manage',
            'course-talks.sessions.manage',
            'course-talks.attendance.manage',
            'course-talks.grades.manage',
            'course-talks.participants.manage',
            'course-talks.documents.generate',
            'course-talks.documents.revoke',
            'course-talks.commercial-documents.manage',
            'course-talks.documents.send',
            'course-talks.templates.manage',
            'course-talks.audit.view',
        ]);

        $this->assertTrue(Gate::forUser($responsible)->allows('view', $edition));
        $this->assertFalse(Gate::forUser($responsible)->allows('manageGrades', $edition));
        $this->assertTrue(Gate::forUser($manager)->allows('create', CourseActivity::class));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $edition));
        $this->assertTrue(Gate::forUser($manager)->allows('manageSessions', $edition));
        $this->assertTrue(Gate::forUser($manager)->allows('manageAttendance', $edition));
        $this->assertTrue(Gate::forUser($manager)->allows('manageGrades', $edition));
        $this->assertTrue(Gate::forUser($manager)->allows('create', CourseEnrollment::class));
        $this->assertTrue(Gate::forUser($manager)->allows('generate', CourseAcademicDocument::class));
        $this->assertTrue(Gate::forUser($manager)->allows('revoke', CourseAcademicDocument::class));
        $this->assertTrue(Gate::forUser($manager)->allows('send', CourseAcademicDocument::class));
        $this->assertTrue(Gate::forUser($manager)->allows('manage', CourseCommercialDocument::class));
        $this->assertTrue(Gate::forUser($manager)->allows('send', CourseCommercialDocument::class));
        $this->assertTrue(Gate::forUser($manager)->allows('manage', CourseCertificateTemplate::class));
        $this->assertTrue(Gate::forUser($manager)->allows('viewAudit', CourseActivity::class));
    }
}
