<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseModality;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\User;
use App\Services\Courses\CourseEnrollmentService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
            ->assertSee('no es un valor válido')
            ->assertSee('no coincide con ningún curso o charla registrado')
            ->assertDontSee('CUR-FILT-001')
            ->assertDontSee('CHA-FILT-001')
            ->assertDontSee('Curso avanzado de saneamiento')
            ->assertDontSee('Charla de cumplimiento normativo');
    }

    /**
     * The measured defect (verification WARNING-5). The filter used to decide
     * "unknown" with an exact-case `CourseActivityType::tryFrom()` while the RAW
     * entry went into the WHERE clause, so `?activity_type=Course` produced a
     * WARNING naming 'Course' AND a WHERE comparing 'Course'. The app's MySQL
     * connection (`utf8mb4_unicode_ci`) treats `'Course' = 'course'` as TRUE, so on
     * MySQL the list showed the courses the warning said it had not found; the
     * SQLite test connection compares case-sensitively and matches nothing. The two
     * engines disagreed and the message was false on one of them.
     *
     * WHAT THIS TEST PINS, AND WHAT IT CANNOT. This suite runs on SQLite, so it
     * CANNOT observe the MySQL half of that divergence — nothing here does, and no
     * assertion below claims to. What it pins is the FIX that makes the engines
     * agree: the entry is resolved through the enum BEFORE the query, so the list is
     * filtered by the enum's own backing value (`course`) on every engine, and no
     * warning is shown because the filter really did apply and nothing false is
     * displayed. The deterministic OUTCOME and the truthful MESSAGE, not the engine
     * comparison itself.
     */
    public function test_a_wrong_case_activity_type_filter_resolves_to_the_enum_value_instead_of_contradicting_the_list(): void
    {
        $user = $this->activityListViewer();
        [$course, $talk] = $this->activityTypeScenario();

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'Course']))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-'.$course->id.'"', false)
            ->assertSee('CUR-FILT-001')
            ->assertSee('Curso avanzado de saneamiento')
            ->assertDontSee('data-testid="course-talks-activity-'.$talk->id.'"', false)
            ->assertDontSee('CHA-FILT-001')
            ->assertDontSee('Charla de cumplimiento normativo')
            // The control shows the resolved type, not the hand-edited entry, so the
            // screen agrees with the list it is filtering.
            ->assertSee('Filtro activo: Curso')
            ->assertSee('value="course" selected', false)
            ->assertDontSee('data-testid="course-talks-activities-type-filter-invalid"', false);
    }

    /**
     * Triangulation of the same resolution: the other type, every capitalisation and
     * the surrounding whitespace a hand-edited URL can carry. Each case must resolve
     * to the SAME enum backing value, which is what makes the outcome independent of
     * the connection's comparison collation.
     */
    public function test_the_activity_type_resolution_covers_the_other_type_any_case_and_surrounding_whitespace(): void
    {
        $user = $this->activityListViewer();
        $this->activityTypeScenario();

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => 'TALK']))
            ->assertOk()
            ->assertSee('CHA-FILT-001')
            ->assertSee('Filtro activo: Charla')
            ->assertDontSee('CUR-FILT-001')
            ->assertDontSee('data-testid="course-talks-activities-type-filter-invalid"', false);

        $this->actingAs($user)
            ->get(route('course-talks.activities.index', ['activity_type' => '  Course  ']))
            ->assertOk()
            ->assertSee('CUR-FILT-001')
            ->assertDontSee('CHA-FILT-001')
            ->assertDontSee('data-testid="course-talks-activities-type-filter-invalid"', false);
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

    // -----------------------------------------------------------------
    // Featured-edition shortcut on the unified list.
    //
    // The activity/edition split is deliberate: an activity is the reusable
    // definition (syllabus, code, academic hours) and an edition is one concrete
    // delivery (dates, modality, price, its own teachers, sessions, participants,
    // grades, certificates and receipts). Every operational screen therefore hangs
    // off the EDITION, so the list offers a way in when the target edition is
    // obvious, with a short label saying WHY that edition was chosen.
    //
    // The rule, resolved in the controller in PHP over the editions loaded by ONE
    // eager query (no `FIELD()`, no engine-specific ordering function, no
    // collation-dependent comparison):
    //
    //   1. an `in_progress` edition; among several, the latest `starts_on`;
    //   2. otherwise the `scheduled` edition with the nearest future `starts_on`;
    //   3. otherwise the most recent edition by `starts_on`;
    //   4. an activity with no editions gets no shortcut, only "Ver detalle".
    //
    // DEVIATION, recorded deliberately: the stated rule says `open`. The enum has
    // no `open` case — its five cases are `draft`, `scheduled`, `in_progress`,
    // `finished`, `cancelled` (`Borrador`, `Programada`, `En curso`, `Finalizada`,
    // `Cancelada`, the module spec's own vocabulary) — so `scheduled`, the
    // published pre-start state, is the `open` the rule means. Nothing else about
    // the rule was adapted.
    //
    // Every assertion below is about the LINK TARGET — the route of the expected
    // edition id — and never about the existence of a button. A test that only
    // counted controls would pass while linking the WRONG edition, which is the
    // defect shape this section exists to prevent.
    // -----------------------------------------------------------------

    private function courseTalkPermissions(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function courseEditionShortcutViewer(array $permissions): User
    {
        $this->courseTalkPermissions();

        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function editionFor(CourseActivity $activity, CourseEditionState $state, ?string $startsOn, string $code): CourseEdition
    {
        return CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => $code,
            'state' => $state,
            'starts_on' => $startsOn,
            'ends_on' => $startsOn === null ? null : Carbon::parse($startsOn)->addDay()->toDateString(),
        ]);
    }

    /**
     * The HTML of ONE activity row, so a negative assertion is about that row and
     * not about the page (whose other rows legitimately carry edition links).
     */
    private function activityRowHtml(TestResponse $response, CourseActivity $activity): string
    {
        $matched = preg_match(
            '/data-testid="course-talks-activity-'.$activity->id.'"(.*?)<\/tr>/s',
            (string) $response->getContent(),
            $matches
        );

        $this->assertSame(1, $matched, 'The row of activity '.$activity->code.' was not found in the list.');

        return $matches[1];
    }

    public function test_the_activity_row_shortcut_targets_the_in_progress_edition_and_says_why(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-001', 'name' => 'Curso de brigadas']);
        $olderInProgress = $this->editionFor($activity, CourseEditionState::InProgress, now()->subDays(30)->toDateString(), 'ED-FEAT-IP-ANT');
        $newerInProgress = $this->editionFor($activity, CourseEditionState::InProgress, now()->subDays(5)->toDateString(), 'ED-FEAT-IP-NEW');
        $scheduled = $this->editionFor($activity, CourseEditionState::Scheduled, now()->addDays(10)->toDateString(), 'ED-FEAT-SCH-010');

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-featured-edition-'.$activity->id.'"', false)
            ->assertSee('data-testid="course-talks-activity-featured-reason-'.$activity->id.'"', false)
            ->assertSee('Dictado en curso')
            ->assertSee('ED-FEAT-IP-NEW')
            ->assertSee('href="'.route('course-talks.attendance.index', $newerInProgress).'"', false)
            ->assertSee('href="'.route('course-talks.enrollments.index', $newerInProgress).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $olderInProgress).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $scheduled).'"', false)
            ->assertDontSee('in_progress');
    }

    public function test_a_scheduled_edition_with_the_nearest_future_date_beats_a_draft_a_past_scheduled_and_a_finished_one(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-002', 'name' => 'Curso de alturas']);
        $finished = $this->editionFor($activity, CourseEditionState::Finished, now()->subDays(90)->toDateString(), 'ED-FEAT-FIN-090');
        $draftSooner = $this->editionFor($activity, CourseEditionState::Draft, now()->addDays(3)->toDateString(), 'ED-FEAT-DRA-003');
        $scheduledPast = $this->editionFor($activity, CourseEditionState::Scheduled, now()->subDays(5)->toDateString(), 'ED-FEAT-SCH-PAST');
        $scheduledNearest = $this->editionFor($activity, CourseEditionState::Scheduled, now()->addDays(20)->toDateString(), 'ED-FEAT-SCH-NEAR');
        $scheduledLater = $this->editionFor($activity, CourseEditionState::Scheduled, now()->addDays(40)->toDateString(), 'ED-FEAT-SCH-LATER');

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('Próximo dictado')
            ->assertSee('ED-FEAT-SCH-NEAR')
            ->assertSee('href="'.route('course-talks.attendance.index', $scheduledNearest).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $finished).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $draftSooner).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $scheduledPast).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $scheduledLater).'"', false);
    }

    public function test_a_scheduled_edition_starting_today_is_still_the_nearest_future_candidate(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-003', 'name' => 'Curso de primeros auxilios']);
        $finished = $this->editionFor($activity, CourseEditionState::Finished, now()->subDays(2)->toDateString(), 'ED-FEAT-FIN-002');
        $startsToday = $this->editionFor($activity, CourseEditionState::Scheduled, now()->toDateString(), 'ED-FEAT-SCH-TODAY');
        $later = $this->editionFor($activity, CourseEditionState::Scheduled, now()->addDays(15)->toDateString(), 'ED-FEAT-SCH-015');

        // An edition scheduled for today is the one the operator is about to run,
        // so the boundary is INCLUSIVE: `starts_on >= today`. The rule is stated in
        // the controller, and this test pins the boundary rather than leaving it to
        // the reader.
        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('Próximo dictado')
            ->assertSee('ED-FEAT-SCH-TODAY')
            ->assertSee('href="'.route('course-talks.attendance.index', $startsToday).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $later).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $finished).'"', false);
    }

    public function test_an_activity_with_only_finished_editions_still_offers_the_most_recent_one(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-004', 'name' => 'Curso de evacuación']);
        $oldest = $this->editionFor($activity, CourseEditionState::Finished, now()->subDays(90)->toDateString(), 'ED-FEAT-FIN-OLD');
        $mostRecent = $this->editionFor($activity, CourseEditionState::Finished, now()->subDays(30)->toDateString(), 'ED-FEAT-FIN-RECENT');

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('Último dictado')
            ->assertSee('ED-FEAT-FIN-RECENT')
            ->assertSee('href="'.route('course-talks.attendance.index', $mostRecent).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $oldest).'"', false);
    }

    public function test_the_most_recent_fallback_rule_skips_a_cancelled_edition(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-005', 'name' => 'Curso de soldadura']);
        $finished = $this->editionFor($activity, CourseEditionState::Finished, now()->subDays(30)->toDateString(), 'ED-FEAT-FIN-030');
        $cancelled = $this->editionFor($activity, CourseEditionState::Cancelled, now()->subDays(5)->toDateString(), 'ED-FEAT-CAN-005');

        // Rule 3 read literally offered the CANCELLED edition here, because it was the
        // most recent by `starts_on`. That was flagged as a product question rather than
        // settled unilaterally, and the answer is no: pointing "go and mark attendance" at
        // the most recent thing that never happened is worse than offering nothing. The
        // fallback now skips cancelled editions and offers the most recent delivery that
        // actually took place.
        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('Último dictado')
            ->assertSee('ED-FEAT-FIN-030')
            ->assertSee('href="'.route('course-talks.attendance.index', $finished).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $cancelled).'"', false);
    }

    public function test_an_activity_whose_editions_were_all_cancelled_offers_no_shortcut(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-006', 'name' => 'Curso suspendido']);
        $cancelled = $this->editionFor($activity, CourseEditionState::Cancelled, now()->subDays(5)->toDateString(), 'ED-FEAT-CAN-ALL');

        // Every edition cancelled is the same outcome as no editions at all: there is no
        // operational destination, so the row keeps only its "Ver detalle" link.
        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('course-talks.attendance.index', $cancelled).'"', false)
            ->assertSee('Ver detalle');
    }

    public function test_an_activity_without_editions_offers_no_operational_shortcut(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $withEditions = CourseActivity::factory()->create(['code' => 'CUR-FEAT-HAS', 'name' => 'Curso con ediciones']);
        $this->editionFor($withEditions, CourseEditionState::InProgress, now()->subDay()->toDateString(), 'ED-FEAT-HAS-001');
        $withoutEditions = CourseActivity::factory()->create(['code' => 'CUR-FEAT-NONE', 'name' => 'Curso sin ediciones']);

        $response = $this->actingAs($user)->get(route('course-talks.activities.index'))->assertOk();

        $response
            ->assertSee('data-testid="course-talks-activity-featured-edition-'.$withEditions->id.'"', false)
            ->assertDontSee('data-testid="course-talks-activity-featured-edition-'.$withoutEditions->id.'"', false)
            ->assertSee('CUR-FEAT-NONE');

        // Scoped to the row itself: the neighbouring row has editions and DOES carry
        // edition links, so a page-level "does not contain" assertion would be both
        // weaker and false.
        $row = $this->activityRowHtml($response, $withoutEditions);

        $this->assertStringNotContainsString('/course-talks/editions/', $row);
        $this->assertStringContainsString(route('course-talks.activities.show', $withoutEditions), $row);
    }

    public function test_the_shortcut_links_mirror_the_edition_page_gates(): void
    {
        $viewer = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-006', 'name' => 'Curso de trabajos en caliente']);
        $featured = $this->editionFor($activity, CourseEditionState::InProgress, now()->subDay()->toDateString(), 'ED-FEAT-006');

        // A plain edition viewer: the edition page renders attendance, participants,
        // documents and comprobantes outside any management gate, and gates teachers,
        // sessions and enrollment with the abilities their own routes require.
        $this->actingAs($viewer)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('href="'.route('course-talks.attendance.index', $featured).'"', false)
            ->assertSee('href="'.route('course-talks.enrollments.index', $featured).'"', false)
            ->assertSee('href="'.route('course-talks.documents.index', $featured).'"', false)
            ->assertSee('href="'.route('course-talks.commercial-documents.index', $featured).'"', false)
            ->assertSee('Asistencia')
            ->assertSee('Participantes')
            ->assertSee('Documentos')
            ->assertSee('Comprobantes')
            ->assertDontSee('href="'.route('course-talks.editions.teachers', $featured).'"', false)
            ->assertDontSee('href="'.route('course-talks.editions.sessions', $featured).'"', false)
            ->assertDontSee('href="'.route('course-talks.enrollments.create', $featured).'"', false)
            ->assertDontSee('href="'.route('course-talks.grades.index', $featured).'"', false);

        // The abilities whose routes the edition page gates: holding them adds exactly
        // those links, and holding `editions.manage` does NOT leak the grade matrix.
        $manager = $this->courseEditionShortcutViewer([
            'course-talks.view',
            'course-talks.editions.manage',
            'course-talks.participants.manage',
        ]);

        $this->actingAs($manager)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('href="'.route('course-talks.editions.teachers', $featured).'"', false)
            ->assertSee('href="'.route('course-talks.editions.sessions', $featured).'"', false)
            ->assertSee('href="'.route('course-talks.enrollments.create', $featured).'"', false)
            ->assertDontSee('href="'.route('course-talks.grades.index', $featured).'"', false);
    }

    public function test_the_grades_shortcut_appears_only_with_the_ability_the_grades_route_requires(): void
    {
        $activity = CourseActivity::factory()->create(['code' => 'CUR-FEAT-007', 'name' => 'Curso de rescate']);
        $featured = $this->editionFor($activity, CourseEditionState::InProgress, now()->subDay()->toDateString(), 'ED-FEAT-007');

        $gradesManager = $this->courseEditionShortcutViewer(['course-talks.view', 'course-talks.grades.manage']);

        $this->actingAs($gradesManager)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('href="'.route('course-talks.grades.index', $featured).'"', false);

        $plainViewer = $this->courseEditionShortcutViewer(['course-talks.view']);

        $this->actingAs($plainViewer)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('course-talks.grades.index', $featured).'"', false)
            ->assertSee('href="'.route('course-talks.attendance.index', $featured).'"', false);
    }

    public function test_the_featured_editions_of_the_whole_list_are_loaded_in_one_query(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $activities = [];

        foreach (range(1, 4) as $index) {
            $activity = CourseActivity::factory()->create([
                'code' => 'CUR-FEAT-Q'.$index,
                'name' => 'Curso de consulta '.$index,
            ]);
            $activities[] = $activity;
            $this->editionFor($activity, CourseEditionState::InProgress, now()->subDays($index)->toDateString(), 'ED-FEAT-Q'.$index);
            $this->editionFor($activity, CourseEditionState::Finished, now()->subDays(30 + $index)->toDateString(), 'ED-FEAT-Q'.$index.'-FIN');
        }

        DB::enableQueryLog();
        $response = $this->actingAs($user)->get(route('course-talks.activities.index'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($query): string => trim((string) $query));
        DB::disableQueryLog();

        $editionListQueries = $queries->filter(
            fn (string $query): bool => (bool) preg_match('/^select \* from ["`]?course_editions["`]?/i', $query)
        );

        // 4 activities, 8 editions: if the controller resolved each row lazily this
        // would be 4 queries instead of 1, so the assertion is falsifiable by
        // removing the single eager load. The rendered output is asserted too, so the
        // query count cannot pass on a page that rendered nothing.
        foreach ($activities as $activity) {
            $response->assertSee('data-testid="course-talks-activity-featured-edition-'.$activity->id.'"', false);
        }

        $response->assertSee('Dictado en curso');

        $this->assertCount(
            1,
            $editionListQueries,
            'The list must load every listed activity\'s editions in ONE query; got: '.$editionListQueries->implode(' | ')
        );
    }

    public function test_the_featured_edition_shortcut_follows_the_activity_type_filter(): void
    {
        $user = $this->courseEditionShortcutViewer(['course-talks.view']);
        $talk = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => 'CHA-FEAT-001',
            'name' => 'Charla con edición en curso',
        ]);
        $talkEdition = $this->editionFor($talk, CourseEditionState::InProgress, now()->subDay()->toDateString(), 'ED-FEAT-CHA-001');
        $course = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-FEAT-008',
            'name' => 'Curso con edición en curso',
        ]);
        $courseEdition = $this->editionFor($course, CourseEditionState::InProgress, now()->subDay()->toDateString(), 'ED-FEAT-CUR-008');

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-featured-edition-'.$talk->id.'"', false)
            ->assertSee('data-testid="course-talks-activity-featured-edition-'.$course->id.'"', false);

        $this->actingAs($user)->get(route('course-talks.activities.index', ['activity_type' => 'talk']))
            ->assertOk()
            ->assertSee('data-testid="course-talks-activity-featured-edition-'.$talk->id.'"', false)
            ->assertDontSee('data-testid="course-talks-activity-featured-edition-'.$course->id.'"', false)
            ->assertSee('href="'.route('course-talks.attendance.index', $talkEdition).'"', false)
            ->assertDontSee('href="'.route('course-talks.attendance.index', $courseEdition).'"', false);
    }

    // -----------------------------------------------------------------
    // The money the operator saved, read back. The claim under test is NOT
    // "a number is printed": it is that the amount these screens show for a
    // delivery is the SAME amount the enrollment persists, so a mistyped price
    // or certificate charge becomes visible on the screen instead of on the
    // invoice. Every assertion below reads money out of its own node or out of
    // the stored row — never out of the page as a whole.
    // -----------------------------------------------------------------

    /** The delivery-detail viewer used by the money tests. */
    private function moneyViewer(): User
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');

        return $user;
    }

    /** A talk that issues a certificate: the only activity that may charge one. */
    private function talkWithCertificate(string $code, string $name): CourseActivity
    {
        return CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'talk_includes_certificate' => true,
            'code' => $code,
            'name' => $name,
        ]);
    }

    /**
     * One money node of the delivery detail, read from its own `data-testid`, so
     * the value is the one that cell renders and not a string that happens to
     * appear somewhere else on the page.
     */
    private function renderedMoney(CourseEdition $edition, User $user, string $testId): string
    {
        $response = $this->actingAs($user)->get(route('course-talks.editions.show', $edition))->assertOk();

        $matched = preg_match(
            '/data-testid="'.preg_quote($testId, '/').'"[^>]*>\s*([^<]*?)\s*</',
            (string) $response->getContent(),
            $matches
        );

        $this->assertSame(1, $matched, 'The delivery detail rendered no node `'.$testId.'`.');

        return $matches[1];
    }

    public function test_the_delivery_detail_renders_the_price_the_certificate_charge_and_the_per_enrollment_total(): void
    {
        $user = $this->moneyViewer();
        $edition = CourseEdition::factory()
            ->for($this->talkWithCertificate('CHA-MON-001', 'Charla de montos visibles'), 'activity')
            ->create([
                'code' => 'ED-MON-001',
                'price_amount' => '100.00',
                'certificate_charge_amount' => '25.00',
            ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('Precio del certificado')
            ->assertSee('Total por matrícula')
            // The exact strings the model holds, in the module's own
            // `amount currency` shape — never a reformatted approximation.
            ->assertSee('100.00 PEN')
            ->assertSee('25.00 PEN')
            ->assertSee('125.00 PEN')
            // The tax belongs to the commercial document, which already renders
            // it; this screen must not grow a second breakdown.
            ->assertDontSee('IGV');

        $this->assertSame('100.00 PEN', $this->renderedMoney($edition, $user, 'course-talks-edition-price'));
        $this->assertSame('25.00 PEN', $this->renderedMoney($edition, $user, 'course-talks-edition-certificate-charge'));
        $this->assertSame('125.00 PEN', $this->renderedMoney($edition, $user, 'course-talks-edition-total'));
    }

    public function test_the_total_the_delivery_detail_renders_is_the_subtotal_the_enrollment_persists(): void
    {
        $user = $this->moneyViewer();
        $edition = CourseEdition::factory()
            ->for($this->talkWithCertificate('CHA-MON-002', 'Charla de totales'), 'activity')
            ->create([
                'code' => 'ED-MON-002',
                'price_amount' => '100.00',
                'certificate_charge_amount' => '25.00',
            ]);

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition, [
            'first_name' => 'Ana', 'last_name' => 'Torres', 'document_type' => 'dni',
            'document_number' => '70999001', 'email' => 'moneda@example.test', 'mobile' => '+51 999 111 222',
        ]);

        // The whole unit exists for this identity: the number the screen SHOWS
        // and the number the enrollment STORED are the same number, because both
        // are the delivery's own money read through ONE definition. Compared as
        // the exact stored strings (no float, no rounding) so a drift of a single
        // cent fails here.
        $this->assertSame('125.00', $enrollment->subtotal_amount);
        $this->assertSame(
            $enrollment->subtotal_amount.' '.$enrollment->currency,
            $this->renderedMoney($edition, $user, 'course-talks-edition-total')
        );
    }

    public function test_a_course_delivery_detail_shows_its_money_but_no_certificate_line(): void
    {
        $user = $this->moneyViewer();
        $edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->state([
                'type' => CourseActivityType::Course,
                'code' => 'CUR-MON-003',
                'name' => 'Curso sin certificado de charla',
            ]), 'activity')
            ->create(['code' => 'ED-MON-003', 'price_amount' => '480.00']);

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition, [
            'first_name' => 'Luz', 'last_name' => 'Ramos', 'document_type' => 'dni',
            'document_number' => '70999002', 'email' => 'curso.moneda@example.test', 'mobile' => '+51 999 111 222',
        ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('480.00 PEN')
            // A course has no talk certificate, so the line does not exist at all
            // — not even reading 0.00, which would suggest a merely empty amount.
            ->assertDontSee('Precio del certificado')
            ->assertDontSee('data-testid="course-talks-edition-certificate-charge"', false)
            ->assertDontSee('IGV');

        $this->assertSame('0.00', $enrollment->certificate_charge_amount);
        $this->assertSame(
            $enrollment->subtotal_amount.' '.$enrollment->currency,
            $this->renderedMoney($edition, $user, 'course-talks-edition-total')
        );
    }

    public function test_a_cancelled_delivery_still_shows_the_money_that_was_agreed(): void
    {
        $user = $this->moneyViewer();
        $edition = CourseEdition::factory()
            ->for($this->talkWithCertificate('CHA-MON-004', 'Charla cancelada'), 'activity')
            ->create([
                'code' => 'ED-MON-004',
                'state' => CourseEditionState::Cancelled,
                'price_amount' => '80.00',
                'certificate_charge_amount' => '20.00',
            ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('Cancelada')
            ->assertSee('80.00 PEN')
            ->assertSee('20.00 PEN')
            ->assertSee('100.00 PEN');
    }

    public function test_the_activity_detail_lists_the_price_of_every_delivery(): void
    {
        $user = $this->moneyViewer();
        $activity = CourseActivity::factory()->create(['code' => 'CUR-MON-005', 'name' => 'Curso de precios visibles']);
        $priced = CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-MON-005',
            'price_amount' => '1234.50',
        ]);
        $free = CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-MON-006',
            'price_amount' => '0.00',
        ]);

        $this->actingAs($user)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertSee('Precio')
            // The exact string the column holds, so a mistyped price is readable
            // on the list itself: never rounded, never re-grouped.
            ->assertSee('data-testid="course-talks-edition-price-'.$priced->id.'"', false)
            ->assertSee('1234.50 PEN')
            ->assertDontSee('1,234.50')
            // A free delivery is a price too, and it must read 0.00 rather than
            // an empty cell nobody can tell from a missing value.
            ->assertSee('data-testid="course-talks-edition-price-'.$free->id.'"', false)
            ->assertSee('0.00 PEN');
    }
}
