<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseModality;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseTalksReadOnlyHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_view_all_activity_types_and_activity_detail(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');
        $course = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-READ-001',
            'name' => 'Curso de Seguridad',
        ]);
        CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => 'CHA-READ-001',
            'name' => 'Charla de Seguridad',
        ]);

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('Curso de Seguridad')
            ->assertSee('Charla de Seguridad')
            ->assertSee('Curso')
            ->assertSee('Charla');

        $this->actingAs($user)->get(route('course-talks.activities.show', $course))
            ->assertOk()
            ->assertSee('CUR-READ-001')
            ->assertSee('Curso de Seguridad');
    }

    public function test_authorized_user_can_view_edition_detail_without_mutation_actions(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');
        $edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->state([
                'type' => CourseActivityType::Talk,
                'name' => 'Charla de Cumplimiento',
            ]), 'activity')
            ->create(['code' => 'ED-READ-001']);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('ED-READ-001')
            ->assertSee('Charla de Cumplimiento')
            ->assertDontSee('Editar')
            ->assertDontSee('Registrar asistencia');
    }

    public function test_users_without_view_permission_cannot_access_read_only_course_talk_routes(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $activity = CourseActivity::factory()->create();
        $edition = CourseEdition::factory()->for($activity, 'activity')->create();

        $this->actingAs($user)->get(route('course-talks.activities.index'))->assertForbidden();
        $this->actingAs($user)->get(route('course-talks.activities.show', $activity))->assertForbidden();
        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login_for_read_only_course_talk_routes(): void
    {
        $activity = CourseActivity::factory()->create();
        $edition = CourseEdition::factory()->for($activity, 'activity')->create();

        $this->get(route('course-talks.activities.index'))->assertRedirect(route('login'));
        $this->get(route('course-talks.activities.show', $activity))->assertRedirect(route('login'));
        $this->get(route('course-talks.editions.show', $edition))->assertRedirect(route('login'));
    }

    public function test_responsible_user_can_view_own_edition_without_view_permission(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $responsible = User::factory()->create(['is_active' => true]);
        $edition = CourseEdition::factory()
            ->for(CourseActivity::factory(), 'activity')
            ->create([
                'code' => 'ED-OWN-001',
                'responsible_user_id' => $responsible->id,
            ]);

        $this->assertFalse($responsible->hasPermissionTo('course-talks.view'));

        $this->actingAs($responsible)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('ED-OWN-001');

        $this->actingAs($responsible)->get(route('course-talks.activities.index'))->assertForbidden();
    }

    public function test_read_only_views_render_spanish_labels_instead_of_enum_slugs(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');
        $activity = CourseActivity::factory()->create([
            'code' => 'CUR-LAB-001',
            'name' => 'Curso de Etiquetas',
        ]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-LAB-001',
            'state' => CourseEditionState::Draft,
            'modality' => CourseModality::Presential,
        ]);
        CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-LAB-002',
            'state' => CourseEditionState::InProgress,
            'modality' => CourseModality::Hybrid,
        ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('Borrador')
            ->assertSee('Presencial')
            ->assertDontSee('presential');

        $this->actingAs($user)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertSee('Borrador')
            ->assertSee('Presencial')
            ->assertSee('En curso')
            ->assertSee('Híbrida')
            ->assertDontSee('presential')
            ->assertDontSee('in_progress');
    }

    public function test_edition_detail_only_renders_http_access_url_as_link(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');
        $edition = CourseEdition::factory()->for(CourseActivity::factory(), 'activity')->create([
            'code' => 'ED-URL-001',
            'access_url' => 'javascript:alert(1)',
        ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('javascript:alert(1)', false)
            ->assertDontSee('href="javascript:alert(1)"', false);

        $edition->update(['access_url' => 'https://meet.example.test']);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('href="https://meet.example.test"', false);

        $edition->update(['access_url' => 'data:text/html;base64,PHNjcmlwdD4=']);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('data:text/html;base64,PHNjcmlwdD4=', false)
            ->assertDontSee('href="data:text/html', false);
    }

    public function test_edition_detail_does_not_load_unused_teacher_and_session_relations(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');
        $edition = CourseEdition::factory()->for(CourseActivity::factory(), 'activity')->create();

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        $this->assertFalse((bool) array_filter($queries, fn (string $query): bool => str_contains($query, 'course_edition_teachers')));
        $this->assertFalse((bool) array_filter($queries, fn (string $query): bool => str_contains($query, 'course_sessions')));
    }

    public function test_activity_detail_renders_only_scalar_syllabus_entries(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');
        $activity = CourseActivity::factory()->create([
            'code' => 'CUR-SYL-001',
            'base_syllabus_json' => ['Modulo 1', ['Modulo 2', 'Modulo 3'], 'Modulo 4'],
        ]);

        $this->actingAs($user)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertSee('Modulo 1')
            ->assertSee('Modulo 4')
            ->assertDontSee('Modulo 2')
            ->assertDontSee('Array');

        $nestedOnly = CourseActivity::factory()->create([
            'code' => 'CUR-SYL-002',
            'base_syllabus_json' => [['Modulo anidado']],
        ]);

        $this->actingAs($user)->get(route('course-talks.activities.show', $nestedOnly))
            ->assertOk()
            ->assertSee('—')
            ->assertDontSee('Modulo anidado')
            ->assertDontSee('Array');
    }
}
