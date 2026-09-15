<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEditionTeacher;
use App\Models\Courses\CourseSession;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Services\Courses\CourseEditionService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6 — authenticated management of an edition's sessions.
 *
 * The controller stays thin: SyncEditionSessionsRequest validates only the
 * attributes CourseEditionService::syncSessions() consumes, the
 * CourseEditionPolicy `update` ability authorizes, and the service owns the
 * upsert rule.
 *
 * The view copy is asserted deliberately: the service upserts by array position
 * (`sort_order = index + 1`) instead of wiping the list like syncTeachers()
 * does, so the form must tell the user it updates sessions by position rather
 * than claiming a full replacement.
 */
class CourseEditionSessionsHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['course-talks.view', 'course-talks.editions.manage']);

        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-SES-001',
            'name' => 'Curso de Sesiones',
        ]);

        $this->edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-SES-001']);
    }

    /** @param array<string, mixed> $attributes */
    private function sessionRow(string $topic, array $attributes = []): array
    {
        return array_merge(['topic' => $topic], $attributes);
    }

    private function sessionsUrl(): string
    {
        return route('course-talks.editions.sessions', $this->edition);
    }

    /** @param array<string, mixed> $payload */
    private function sync(array $payload): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from($this->sessionsUrl())
            ->post(route('course-talks.editions.sessions.sync', $this->edition), $payload);
    }

    private function seedSessions(): void
    {
        app(CourseEditionService::class)->syncSessions($this->edition, [
            $this->sessionRow('Introducción', [
                'session_date' => '2026-09-15',
                'starts_at' => '09:00',
                'ends_at' => '11:00',
                'teacher_name' => 'Ana Docente',
            ]),
            $this->sessionRow('Cierre', [
                'session_date' => '2026-09-16',
                'starts_at' => '09:00',
                'ends_at' => '11:00',
            ]),
        ]);
    }

    public function test_guests_are_redirected_to_login_from_session_management_routes(): void
    {
        $this->get($this->sessionsUrl())->assertRedirect(route('login'));

        $this->post(route('course-talks.editions.sessions.sync', $this->edition), [
            'sessions' => [$this->sessionRow('Inicio')],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_sessions', 0);
    }

    public function test_users_without_edition_manage_permission_cannot_view_or_sync_sessions(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');
        $this->seedSessions();

        $this->actingAs($viewer)->get($this->sessionsUrl())->assertForbidden();

        $this->actingAs($viewer)->post(route('course-talks.editions.sessions.sync', $this->edition), [
            'sessions' => [$this->sessionRow('Intruso')],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_sessions', ['topic' => 'Introducción']);
        $this->assertDatabaseMissing('course_sessions', ['topic' => 'Intruso']);
    }

    public function test_view_shows_the_current_sessions_with_their_dates_and_times(): void
    {
        $this->seedSessions();

        $this->actingAs($this->manager)->get($this->sessionsUrl())
            ->assertOk()
            ->assertSee('ED-SES-001')
            ->assertSee('Sesiones del dictado')
            ->assertSee('Introducción')
            ->assertSee('15/09/2026')
            ->assertSee('09:00')
            ->assertSee('11:00')
            ->assertSee('Ana Docente')
            // The service upserts by position; the copy must not claim the
            // teachers surface's "replaces the whole list" behaviour.
            ->assertSee('Guardar actualiza las sesiones por posición')
            ->assertDontSee('Guardar reemplaza toda la lista');

        // The sessions routes must not shadow the edition detail binding.
        $this->actingAs($this->manager)->get(route('course-talks.editions.show', $this->edition))
            ->assertOk()
            ->assertSee('ED-SES-001');
    }

    public function test_course_sessions_have_nullable_teacher_assignment_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('course_sessions', 'teacher_id'));

        $session = CourseSession::factory()->for($this->edition, 'edition')->create([
            'teacher_id' => null,
            'teacher_name' => 'Docente legado',
        ]);

        $this->assertNull($session->fresh()->teacher_id);
    }

    public function test_authorized_sync_assigns_same_edition_teacher_and_renders_teacher_select(): void
    {
        $teacher = CourseEditionTeacher::query()->create([
            'course_edition_id' => $this->edition->id,
            'display_name' => 'Ana Identidad',
            'sort_order' => 1,
        ]);

        $this->sync(['sessions' => [
            $this->sessionRow('Introducción', [
                'teacher_id' => $teacher->id,
                'teacher_name' => 'Texto legado',
            ]),
        ]])->assertRedirect($this->sessionsUrl());

        $this->assertDatabaseHas('course_sessions', [
            'course_edition_id' => $this->edition->id,
            'sort_order' => 1,
            'topic' => 'Introducción',
            'teacher_id' => $teacher->id,
            'teacher_name' => 'Texto legado',
        ]);

        $this->actingAs($this->manager)->get($this->sessionsUrl())
            ->assertOk()
            ->assertSee('Ana Identidad')
            ->assertSee('value="'.$teacher->id.'" selected', false);
    }

    public function test_cross_edition_teacher_id_is_rejected_and_not_persisted(): void
    {
        $foreignEdition = CourseEdition::factory()->create();
        $foreignTeacher = CourseEditionTeacher::query()->create([
            'course_edition_id' => $foreignEdition->id,
            'display_name' => 'Docente Externo',
            'sort_order' => 1,
        ]);

        $this->sync(['sessions' => [
            $this->sessionRow('Intrusa', ['teacher_id' => $foreignTeacher->id]),
        ]])->assertRedirect($this->sessionsUrl())
            ->assertSessionHasErrors('sessions.0.teacher_id');

        $this->assertDatabaseCount('course_sessions', 0);
    }

    public function test_clearing_teacher_assignment_keeps_legacy_teacher_name(): void
    {
        $teacher = CourseEditionTeacher::query()->create([
            'course_edition_id' => $this->edition->id,
            'display_name' => 'Ana Identidad',
            'sort_order' => 1,
        ]);

        app(CourseEditionService::class)->syncSessions($this->edition, [
            $this->sessionRow('Introducción', ['teacher_id' => $teacher->id, 'teacher_name' => 'Texto legado']),
        ]);

        $this->sync(['sessions' => [
            $this->sessionRow('Introducción', ['teacher_id' => '', 'teacher_name' => 'Texto legado']),
        ]])->assertRedirect($this->sessionsUrl());

        $session = CourseSession::query()->where('course_edition_id', $this->edition->id)->sole();
        $this->assertNull($session->teacher_id);
        $this->assertSame('Texto legado', $session->teacher_name);
    }

    public function test_authorized_sync_persists_sessions_by_array_position(): void
    {
        $this->sync(['sessions' => [
            $this->sessionRow('Introducción', [
                'session_date' => '2026-09-15',
                'starts_at' => '09:00',
                'ends_at' => '11:00',
                'teacher_name' => 'Ana Docente',
            ]),
            $this->sessionRow('Cierre', ['session_date' => '2026-09-16']),
        ]])
            ->assertRedirect($this->sessionsUrl())
            ->assertSessionHas('status');

        $this->assertDatabaseCount('course_sessions', 2);
        // `session_date` carries the model's `date` cast, which stores midnight in
        // the datetime column; the view formats it back to d/m/Y.
        $this->assertDatabaseHas('course_sessions', [
            'course_edition_id' => $this->edition->id,
            'sort_order' => 1,
            'topic' => 'Introducción',
            'session_date' => '2026-09-15 00:00:00',
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'teacher_name' => 'Ana Docente',
        ]);
        $this->assertDatabaseHas('course_sessions', [
            'course_edition_id' => $this->edition->id,
            'sort_order' => 2,
            'topic' => 'Cierre',
            'session_date' => '2026-09-16 00:00:00',
            'starts_at' => null,
            'ends_at' => null,
            'teacher_name' => null,
        ]);
    }

    public function test_missing_topic_is_rejected_for_every_entry(): void
    {
        $this->seedSessions();

        // A whitespace-only topic becomes an empty (required-failing) value, and
        // an entry that omits the key entirely is equally rejected.
        $this->sync(['sessions' => [
            ['topic' => '   ', 'session_date' => '2026-09-20'],
            ['session_date' => '2026-09-21'],
        ]])
            ->assertRedirect($this->sessionsUrl())
            ->assertSessionHasErrors(['sessions.0.topic', 'sessions.1.topic']);

        // No partial write survived the rejected payload.
        $this->assertDatabaseCount('course_sessions', 2);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 1, 'topic' => 'Introducción']);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 2, 'topic' => 'Cierre']);
    }

    public function test_invalid_date_and_time_values_are_rejected(): void
    {
        $this->sync(['sessions' => [$this->sessionRow('Inicio', ['session_date' => 'no-es-una-fecha'])]])
            ->assertSessionHasErrors('sessions.0.session_date');

        $this->sync(['sessions' => [$this->sessionRow('Inicio', ['starts_at' => '99:99'])]])
            ->assertSessionHasErrors('sessions.0.starts_at');

        $this->sync(['sessions' => [$this->sessionRow('Inicio', ['ends_at' => 'mediodía'])]])
            ->assertSessionHasErrors('sessions.0.ends_at');

        $this->assertDatabaseCount('course_sessions', 0);
    }

    public function test_client_supplied_sort_order_is_ignored_and_the_array_position_wins(): void
    {
        $this->sync(['sessions' => [
            $this->sessionRow('Introducción', ['sort_order' => 99]),
            $this->sessionRow('Cierre', ['sort_order' => 1]),
        ]])->assertRedirect($this->sessionsUrl());

        $this->assertDatabaseHas('course_sessions', ['topic' => 'Introducción', 'sort_order' => 1]);
        $this->assertDatabaseHas('course_sessions', ['topic' => 'Cierre', 'sort_order' => 2]);
        $this->assertDatabaseMissing('course_sessions', ['sort_order' => 99]);
    }

    public function test_sync_updates_existing_sessions_by_position_and_appends_the_next_position(): void
    {
        $this->seedSessions();

        $this->sync(['sessions' => [
            $this->sessionRow('Inicio actualizado', [
                'session_date' => '2026-09-20',
                'starts_at' => '08:00',
                'ends_at' => '10:00',
                'teacher_name' => 'Ana Docente',
            ]),
            $this->sessionRow('Cierre actualizado'),
            $this->sessionRow('Sesión adicional', ['session_date' => '2026-09-22']),
        ]])->assertRedirect($this->sessionsUrl());

        $this->assertDatabaseCount('course_sessions', 3);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 1, 'topic' => 'Inicio actualizado', 'starts_at' => '08:00']);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 2, 'topic' => 'Cierre actualizado']);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 3, 'topic' => 'Sesión adicional', 'session_date' => '2026-09-22 00:00:00']);
    }

    public function test_soft_deleted_sessions_are_restored_when_their_position_is_saved_again(): void
    {
        $this->seedSessions();

        CourseSession::query()
            ->where('course_edition_id', $this->edition->id)
            ->where('sort_order', 2)
            ->delete();

        $this->assertSoftDeleted('course_sessions', [
            'course_edition_id' => $this->edition->id,
            'sort_order' => 2,
        ]);

        $this->sync(['sessions' => [
            $this->sessionRow('Introducción'),
            $this->sessionRow('Cierre restaurado'),
        ]])->assertRedirect($this->sessionsUrl());

        $this->assertNotSoftDeleted('course_sessions', [
            'course_edition_id' => $this->edition->id,
            'sort_order' => 2,
        ]);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 2, 'topic' => 'Cierre restaurado']);
        $this->assertDatabaseCount('course_sessions', 2);
    }

    public function test_optional_new_session_slot_only_appends_a_session_when_filled(): void
    {
        // The optional "new session" slot is appended when filled...
        $this->sync([
            'sessions' => [$this->sessionRow('Inicio')],
            'new_session' => $this->sessionRow('Sesión nueva', ['session_date' => '2026-10-01']),
        ])->assertRedirect($this->sessionsUrl());

        $this->assertDatabaseHas('course_sessions', ['sort_order' => 2, 'topic' => 'Sesión nueva']);

        // ...and ignored when the form is re-submitted untouched.
        $this->sync([
            'sessions' => [$this->sessionRow('Inicio'), $this->sessionRow('Sesión nueva')],
            'new_session' => ['topic' => '', 'session_date' => '', 'starts_at' => '', 'ends_at' => '', 'teacher_name' => ''],
        ])->assertRedirect($this->sessionsUrl());

        $this->assertDatabaseCount('course_sessions', 2);
    }

    public function test_malformed_session_payloads_are_reported_instead_of_coerced(): void
    {
        // A scalar where an array of sessions is expected.
        $this->sync(['sessions' => 'Inicio'])->assertSessionHasErrors('sessions');

        // A scalar entry where an array entry is expected.
        $this->sync(['sessions' => ['Inicio']])->assertSessionHasErrors('sessions.0');

        // A nested array where the optional date is expected: the request must
        // reject the shape it cannot persist rather than coercing it.
        $this->sync(['sessions' => [$this->sessionRow('Inicio', ['session_date' => ['2026-09-15']])]])
            ->assertSessionHasErrors('sessions.0.session_date');

        $this->assertDatabaseCount('course_sessions', 0);
    }

    public function test_saving_fewer_sessions_than_exist_does_not_delete_the_omitted_ones(): void
    {
        app(CourseEditionService::class)->syncSessions($this->edition, [
            $this->sessionRow('Sesión 1'),
            $this->sessionRow('Sesión 2'),
            $this->sessionRow('Sesión 3'),
        ]);

        $this->sync(['sessions' => [
            $this->sessionRow('Sesión 1 actualizada'),
            $this->sessionRow('Sesión 2 actualizada'),
        ]])->assertRedirect($this->sessionsUrl());

        // syncSessions() upserts by position and never wipes the list, so the
        // third session survives the save. This is exactly why the view copy
        // must not claim the teachers surface's full-replacement behaviour.
        $this->assertDatabaseCount('course_sessions', 3);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 1, 'topic' => 'Sesión 1 actualizada']);
        $this->assertDatabaseHas('course_sessions', ['sort_order' => 3, 'topic' => 'Sesión 3']);
    }

    public function test_field_less_service_failure_is_reported_on_the_sessions_view(): void
    {
        $this->app->instance(CourseEditionService::class, new class extends CourseEditionService
        {
            public function syncSessions(CourseEdition $edition, array $sessions): void
            {
                throw new InvalidCourseEditionData('No se pudieron actualizar las sesiones.');
            }
        });

        $this->sync(['sessions' => [$this->sessionRow('Inicio')]])
            ->assertRedirect($this->sessionsUrl());

        // Asserted on the rendered page: `fieldErrors()` maps a field-less
        // exception to the generic `edition` key, which the show view renders.
        $this->actingAs($this->manager)->get($this->sessionsUrl())
            ->assertOk()
            ->assertSee('No se pudieron actualizar las sesiones.');
    }

    public function test_session_form_rerenders_a_rejected_nested_field_without_crashing(): void
    {
        // The same crash class as the syllabus-array defect: a nested entry that
        // fails validation survives into `withInput()`, so the re-rendered form
        // must print it as text instead of handing an array to htmlspecialchars().
        $this->actingAs($this->manager)
            ->from($this->sessionsUrl())
            ->followingRedirects()
            ->post(route('course-talks.editions.sessions.sync', $this->edition), [
                'sessions' => [$this->sessionRow('Inicio', ['teacher_name' => ['A', 'B']])],
            ])
            ->assertOk()
            ->assertSee('debe ser una cadena de texto');

        $this->assertDatabaseCount('course_sessions', 0);
    }
}
