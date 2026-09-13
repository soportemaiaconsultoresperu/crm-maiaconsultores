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

    public function test_course_permissions_are_seeded_and_assignable(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);

        $user->givePermissionTo(CoursePermissionsSeeder::PERMISSIONS);

        foreach (CoursePermissionsSeeder::PERMISSIONS as $permission) {
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

        // The responsible user deliberately holds NO course permission: the
        // ownership clause is then the only thing that can make `view` pass, so
        // deleting that clause can no longer leave this test green.
        $manager->givePermissionTo(CoursePermissionsSeeder::PERMISSIONS);

        $this->assertFalse($responsible->fresh()->can('course-talks.view'));
        $this->assertFalse($responsible->fresh()->can('course-talks.activities.manage'));

        // Ownership clause, exercised on its own.
        $this->assertTrue(Gate::forUser($responsible)->allows('view', $edition));

        // Same ability through the permission, by a user who is NOT responsible.
        $this->assertTrue(Gate::forUser($manager)->allows('view', $edition));

        // Negative case: neither responsible nor permitted.
        $outsider = User::factory()->create(['is_active' => true]);
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $edition));
        $this->assertFalse(Gate::forUser($outsider)->allows('viewAny', CourseActivity::class));

        $this->assertFalse(Gate::forUser($responsible)->allows('manageGrades', $edition));
        $this->assertFalse(Gate::forUser($responsible)->allows('manageSessions', $edition));
        $this->assertFalse(Gate::forUser($outsider)->allows('manageSessions', $edition));
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

    /**
     * Finding 1 — `course-talks.sessions.manage` used to be consumed only by a
     * policy method nothing invoked. The session surface must really be gated by
     * it, so a user who holds it (and does NOT hold `editions.manage`) opens the
     * form and saves sessions.
     */
    public function test_the_sessions_manage_permission_is_consumed_by_the_session_surface(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $scheduler = User::factory()->create(['is_active' => true]);
        $scheduler->givePermissionTo(['course-talks.view', 'course-talks.sessions.manage']);

        $edition = CourseEdition::factory()->create([
            'responsible_user_id' => User::factory()->create()->id,
        ]);

        $this->assertTrue($scheduler->hasPermissionTo('course-talks.sessions.manage'));
        $this->assertFalse($scheduler->hasPermissionTo('course-talks.editions.manage'));
        $this->assertTrue(Gate::forUser($scheduler)->allows('manageSessions', $edition));

        $this->actingAs($scheduler)
            ->get(route('course-talks.editions.sessions', $edition))
            ->assertOk();

        $this->actingAs($scheduler)
            ->post(route('course-talks.editions.sessions.sync', $edition), [
                'sessions' => [['topic' => 'Clase uno']],
            ])
            ->assertRedirect(route('course-talks.editions.sessions', $edition));

        $this->assertDatabaseHas('course_sessions', [
            'course_edition_id' => $edition->id,
            'topic' => 'Clase uno',
        ]);
    }
}
