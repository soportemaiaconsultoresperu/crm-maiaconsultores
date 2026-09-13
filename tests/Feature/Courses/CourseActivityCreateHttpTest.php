<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Models\Courses\CourseActivity;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 6 — authenticated "create activity" HTTP surface.
 *
 * The controller stays thin: the FormRequest validates the attributes the
 * existing CourseActivityService::create() consumes, the policy authorizes,
 * and the service owns the domain rules (code uniqueness, defaults).
 */
class CourseActivityCreateHttpTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['course-talks.view', 'course-talks.activities.manage']);

        return $user;
    }

    public function test_guests_are_redirected_to_login_from_activity_create_and_store(): void
    {
        $this->get(route('course-talks.activities.create'))->assertRedirect(route('login'));

        $this->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-GUEST-001',
            'name' => 'Curso invitado',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_users_without_manage_permission_cannot_create_or_store_activities(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $this->actingAs($viewer)->get(route('course-talks.activities.create'))->assertForbidden();

        $this->actingAs($viewer)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-DENIED-001',
            'name' => 'Curso no autorizado',
        ])->assertForbidden();

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_authorized_user_can_create_an_activity_and_see_it_in_the_index(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->get(route('course-talks.activities.create'))
            ->assertOk()
            ->assertSee('Nueva actividad')
            ->assertSee('Curso')
            ->assertSee('Charla');

        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-NEW-001',
            'name' => 'Curso de Alta',
            'official_academic_hours' => '16.00',
            'base_syllabus_json' => ['Modulo 1', '', 'Modulo 2'],
            'talk_includes_certificate' => '0',
            'is_active' => '1',
        ])->assertRedirect(route('course-talks.activities.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('course_activities', [
            'code' => 'CUR-NEW-001',
            'type' => CourseActivityType::Course->value,
        ]);

        $activity = CourseActivity::query()->where('code', 'CUR-NEW-001')->firstOrFail();
        $this->assertSame('Curso de Alta', $activity->name);
        $this->assertSame('curso-de-alta', $activity->slug);
        $this->assertSame('16.00', $activity->official_academic_hours);
        $this->assertSame(['Modulo 1', 'Modulo 2'], $activity->base_syllabus_json);
        $this->assertTrue($activity->is_active);

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('CUR-NEW-001')
            ->assertSee('Curso de Alta');
    }

    public function test_validation_failure_returns_errors_without_persisting(): void
    {
        $user = $this->manager();

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
                'type' => 'not-a-valid-type',
                'name' => '',
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors(['type', 'code', 'name']);

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_talk_activity_flags_are_persisted_through_the_service(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Talk->value,
            'code' => 'CHA-NEW-001',
            'name' => 'Charla de Alta',
            'talk_includes_certificate' => '1',
            'talk_certificate_price' => '25.00',
            'is_active' => '1',
        ])->assertRedirect(route('course-talks.activities.index'));

        $activity = CourseActivity::query()->where('code', 'CHA-NEW-001')->firstOrFail();
        $this->assertSame(CourseActivityType::Talk, $activity->type);
        $this->assertTrue($activity->talk_includes_certificate);
        $this->assertSame('25.00', $activity->talk_certificate_price);
        $this->assertSame([], $activity->base_syllabus_json);
    }

    public function test_duplicate_activity_code_is_rejected_without_creating_a_second_record(): void
    {
        $user = $this->manager();
        CourseActivity::factory()->create(['code' => 'CUR-DUP-001', 'name' => 'Curso Original']);

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
                'type' => CourseActivityType::Course->value,
                'code' => 'CUR-DUP-001',
                'name' => 'Curso Duplicado',
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('course_activities', 1);
        $this->assertDatabaseHas('course_activities', [
            'code' => 'CUR-DUP-001',
            'name' => 'Curso Original',
        ]);
    }

    public function test_activity_index_shows_the_create_affordance_only_to_authorized_users(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo(['course-talks.view', 'course-talks.activities.manage']);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $createUrl = route('course-talks.activities.create');

        $this->actingAs($manager)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee($createUrl, false);

        $this->actingAs($viewer)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertDontSee($createUrl, false);
    }
}
