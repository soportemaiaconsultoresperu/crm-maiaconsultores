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

    // -----------------------------------------------------------------
    // Activity-type filter on the unified list. The spec scenario
    // "Filter activities by type" is a normative MUST: the user MUST be able to
    // filter by `Curso`, `Charla`, or all activities. The control is NOT the
    // claim under test — every assertion below is about the LIST a query
    // parameter produces, plus the visible report of a rejected value.
    // -----------------------------------------------------------------

    /**
     * One course and one talk, with names and codes that appear nowhere else, so
     * a "does not contain" assertion is about the ROW and never about the words
     * `Curso` / `Charla` (which the page title, the `Tipo` header, the filter
     * options and the badges all print).
     *
     * @return array{0: CourseActivity, 1: CourseActivity}
     */
    private function activityTypeScenario(): array
    {
        $course = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-FILT-001',
            'name' => 'Curso avanzado de saneamiento',
        ]);
        $talk = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => 'CHA-FILT-001',
            'name' => 'Charla de cumplimiento normativo',
        ]);

        return [$course, $talk];
    }

    private function activityListViewer(): User
    {
        $this->seed(CoursePermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');

        return $user;
    }

    public function test_the_activity_list_without_a_filter_shows_every_activity_type(): void
    {
        $user = $this->activityListViewer();
        [$course, $talk] = $this->activityTypeScenario();

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('CUR-FILT-001')
            ->assertSee('CHA-FILT-001')
            ->assertSee('Curso avanzado de saneamiento')
            ->assertSee('Charla de cumplimiento normativo')
            ->assertSee('data-testid="course-talks-activity-'.$course->id.'"', false)
            ->assertSee('data-testid="course-talks-activity-'.$talk->id.'"', false);
    }

    public function test_filtering_the_activity_list_by_course_hides_talks(): void
    {
        $user = $this->activityListViewer();
        [$course, $talk] = $this->activityTypeScenario();

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'course']))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-'.$course->id.'"', false)
            ->assertSee('CUR-FILT-001')
            ->assertSee('Curso avanzado de saneamiento')
            ->assertDontSee('data-testid="course-talks-activity-'.$talk->id.'"', false)
            ->assertDontSee('CHA-FILT-001')
            ->assertDontSee('Charla de cumplimiento normativo');
    }

    public function test_filtering_the_activity_list_by_talk_hides_courses(): void
    {
        $user = $this->activityListViewer();
        [$course, $talk] = $this->activityTypeScenario();

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'talk']))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-'.$talk->id.'"', false)
            ->assertSee('CHA-FILT-001')
            ->assertSee('Charla de cumplimiento normativo')
            ->assertDontSee('data-testid="course-talks-activity-'.$course->id.'"', false)
            ->assertDontSee('CUR-FILT-001')
            ->assertDontSee('Curso avanzado de saneamiento');
    }

    public function test_the_activity_type_filter_control_uses_the_enum_vocabulary_and_marks_the_active_filter(): void
    {
        $user = $this->activityListViewer();
        $this->activityTypeScenario();

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'talk']))
            ->assertOk()
            ->assertSee('name="activity_type"', false)
            ->assertSee('value="course"', false)
            ->assertSee('value="talk" selected', false)
            ->assertSee('data-testid="course-talks-activities-type-filter-active"', false);
    }

    public function test_an_unknown_activity_type_filter_narrows_to_nothing_and_is_reported(): void
    {
        $user = $this->activityListViewer();
        $this->activityTypeScenario();

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'webinar']))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activities-type-filter-invalid"', false)
            ->assertSee('webinar')
            ->assertDontSee('CUR-FILT-001')
            ->assertDontSee('CHA-FILT-001')
            ->assertDontSee('Curso avanzado de saneamiento')
            ->assertDontSee('Charla de cumplimiento normativo');
    }

    public function test_a_non_scalar_activity_type_filter_is_discarded_and_reported_instead_of_failing(): void
    {
        $user = $this->activityListViewer();
        $this->activityTypeScenario();

        // An array where a scalar is expected must never reach a query: it is not a
        // filter, so the list stays complete and the screen says the value was
        // discarded. A 500 is the outcome this locks out.
        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => ['course']]))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activities-type-filter-invalid"', false)
            ->assertSee('CUR-FILT-001')
            ->assertSee('CHA-FILT-001');
    }

    public function test_the_activity_type_filter_stays_behind_the_same_permission_as_the_list(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $unauthorized = User::factory()->create(['is_active' => true]);

        $this->actingAs($unauthorized)
            ->get(route('course-talks.activities.index', ['activity_type' => 'course']))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Triangulation: cases that are NOT the one the filter was written for.
    // -----------------------------------------------------------------

    public function test_the_type_filter_narrows_each_type_independently_when_both_types_repeat(): void
    {
        $user = $this->activityListViewer();
        $talk = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => 'CHA-FILT-001',
            'name' => 'Charla de cumplimiento normativo',
        ]);
        CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => 'CHA-FILT-002',
            'name' => 'Charla de liderazgo',
        ]);
        $firstCourse = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-FILT-001',
            'name' => 'Curso avanzado de saneamiento',
        ]);
        $secondCourse = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-FILT-002',
            'name' => 'Curso de seguridad industrial',
        ]);

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'course']))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-'.$firstCourse->id.'"', false)
            ->assertSee('data-testid="course-talks-activity-'.$secondCourse->id.'"', false)
            ->assertSee('Curso de seguridad industrial')
            ->assertDontSee('data-testid="course-talks-activity-'.$talk->id.'"', false)
            ->assertDontSee('Charla de cumplimiento normativo')
            ->assertDontSee('Charla de liderazgo');

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'talk']))
            ->assertOk()
            ->assertSee('Charla de cumplimiento normativo')
            ->assertSee('Charla de liderazgo')
            ->assertDontSee('Curso avanzado de saneamiento')
            ->assertDontSee('Curso de seguridad industrial');
    }

    public function test_a_blank_activity_type_filter_is_the_same_as_no_filter(): void
    {
        $user = $this->activityListViewer();
        $this->activityTypeScenario();

        // A blank parameter is an absent filter, not an unknown value: the full list
        // comes back and nothing is reported, so a cleared form never looks like a
        // failed filter.
        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => '']))
            ->assertOk()
            ->assertDontSee('data-testid="course-talks-activities-type-filter-invalid"', false)
            ->assertSee('CUR-FILT-001')
            ->assertSee('CHA-FILT-001');

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => '   ']))
            ->assertOk()
            ->assertDontSee('data-testid="course-talks-activities-type-filter-invalid"', false)
            ->assertSee('CUR-FILT-001')
            ->assertSee('CHA-FILT-001');
    }

    public function test_the_filter_control_is_a_linkable_get_form_that_keeps_the_active_choice(): void
    {
        $user = $this->activityListViewer();
        $this->activityTypeScenario();

        $unfiltered = $this->actingAs($user)->get(route('course-talks.activities.index'))->assertOk();

        $unfiltered->assertSee('action="'.route('course-talks.activities.index').'"', false)
            ->assertSee('method="GET"', false)
            ->assertSee('value="" selected', false)
            ->assertSee('id="activities-type"', false)
            ->assertSee('for="activities-type"', false)
            ->assertDontSee('data-testid="course-talks-activities-type-filter-active"', false);

        $filtered = $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'talk']))
            ->assertOk();

        $filtered->assertSee('value="talk" selected', false)
            ->assertDontSee('value="" selected', false)
            ->assertSee('Filtro activo: Charla');
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
