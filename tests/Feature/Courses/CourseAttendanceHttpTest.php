<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseAttendance;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\User;
use App\Services\Courses\CourseAttendanceService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6.c — authenticated attendance matrix of one edition.
 *
 * The controller stays thin: StoreCourseAttendanceRequest validates only the
 * shape of the submitted cells, CourseEditionPolicy authorizes (view for
 * reading, manageAttendance for marking), and CourseAttendanceService owns
 * every attendance rule — valid statuses, the same-edition check, the talk
 * participation refresh and the eligibility trigger. The matrix only renders
 * what the domain decided.
 */
class CourseAttendanceHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['course-talks.view', 'course-talks.attendance.manage']);

        $this->edition = $this->edition();
    }

    private function edition(CourseActivityType $type = CourseActivityType::Course, string $code = 'ED-ATT-001'): CourseEdition
    {
        $isTalk = $type === CourseActivityType::Talk;

        $activity = CourseActivity::factory()->create([
            'type' => $type,
            'code' => ($isTalk ? 'CHR-' : 'CUR-').$code,
            'name' => $isTalk ? 'Charla de asistencias' : 'Curso de asistencias',
        ]);

        return CourseEdition::factory()->for($activity, 'activity')->create(['code' => $code]);
    }

    private function courseSession(CourseEdition $edition, string $date, int $sortOrder, string $topic): CourseSession
    {
        return CourseSession::factory()->for($edition, 'edition')->create([
            'session_date' => $date,
            'sort_order' => $sortOrder,
            'topic' => $topic,
        ]);
    }

    private function enrollment(CourseEdition $edition, string $lastName, string $documentNumber, string $firstName = 'Luz'): CourseEnrollment
    {
        $participant = CourseParticipant::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'document_number' => $documentNumber,
            'document_number_norm' => $documentNumber,
        ]);

        return CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create();
    }

    /** @return array{enrollment_id: int, session_id: int, status: string} */
    private function cell(CourseEnrollment $enrollment, CourseSession $session, string $status): array
    {
        return [
            'enrollment_id' => $enrollment->id,
            'session_id' => $session->id,
            'status' => $status,
        ];
    }

    private function indexUrl(?CourseEdition $edition = null): string
    {
        return route('course-talks.attendance.index', $edition ?? $this->edition);
    }

    /** @param array<int, mixed> $cells */
    private function store(array $cells, ?CourseEdition $edition = null): TestResponse
    {
        $edition ??= $this->edition;

        return $this->actingAs($this->manager)
            ->from($this->indexUrl($edition))
            ->post(route('course-talks.attendance.store', $edition), ['cells' => $cells]);
    }

    public function test_guests_are_redirected_to_login_from_the_attendance_routes(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->get($this->indexUrl())->assertRedirect(route('login'));

        $this->post(route('course-talks.attendance.store', $this->edition), [
            'cells' => [$this->cell($enrollment, $session, 'present')],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_attendances', 0);
    }

    public function test_users_without_module_permission_cannot_reach_the_attendance_matrix(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->actingAs($stranger)->get($this->indexUrl())->assertForbidden();
        $this->actingAs($stranger)->post(route('course-talks.attendance.store', $this->edition), [
            'cells' => [$this->cell($enrollment, $session, 'present')],
        ])->assertForbidden();

        $this->assertDatabaseCount('course_attendances', 0);
    }

    public function test_viewers_without_attendance_permission_read_the_matrix_but_cannot_mark(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        app(CourseAttendanceService::class)->mark($session, $enrollment, 'present', $this->manager);

        $this->actingAs($viewer)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Ramos, Luz')
            ->assertSee('Presente')
            // Read-only: no editable cell and no submit control.
            ->assertDontSee('cells[')
            ->assertDontSee('Guardar asistencia');

        $this->actingAs($viewer)->post(route('course-talks.attendance.store', $this->edition), [
            'cells' => [$this->cell($enrollment, $session, 'absent')],
        ])->assertForbidden();

        $this->assertSame('present', CourseAttendance::sole()->status);
    }

    public function test_the_matrix_lists_every_enrollment_by_session_with_the_stored_status(): void
    {
        // Created out of display order on purpose: the matrix orders by session
        // date and sort order, not by creation.
        $second = $this->courseSession($this->edition, '2026-04-02', 2, 'Sesión dos');
        $first = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');

        $luz = $this->enrollment($this->edition, 'Ramos', '11111111');
        $marco = $this->enrollment($this->edition, 'Diaz', '22222222', 'Marco');

        app(CourseAttendanceService::class)->mark($first, $luz, 'late', $this->manager);

        $otherEdition = $this->edition(CourseActivityType::Course, 'ED-ATT-002');
        $this->courseSession($otherEdition, '2026-04-01', 1, 'Sesión de otra edición');
        $this->enrollment($otherEdition, 'Ajeno', '99999999');

        $response = $this->actingAs($this->manager)->get($this->indexUrl());

        $response->assertOk()
            ->assertSee('ED-ATT-001')
            ->assertSee('Curso de asistencias')
            ->assertSee('Ramos, Luz')
            ->assertSee('Diaz, Marco')
            // The stored status is rendered as the current cell value.
            ->assertSee('Tardanza')
            ->assertSee('value="late" selected', false)
            // A cell without an attendance row starts unmarked.
            ->assertSee('Sin marcar')
            ->assertDontSee('Sesión de otra edición')
            ->assertDontSee('Ajeno');

        $content = $response->getContent();

        $this->assertLessThan(
            strpos($content, 'Sesión dos'),
            strpos($content, 'Sesión uno'),
            'The first session column must be rendered before the second one.',
        );
        $this->assertLessThan(
            strpos($content, 'Diaz, Marco'),
            strpos($content, 'Ramos, Luz'),
            'Enrollment rows must keep a stable order.',
        );
    }

    public function test_bulk_marking_persists_every_submitted_cell_with_the_authenticated_actor(): void
    {
        $first = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $second = $this->courseSession($this->edition, '2026-04-02', 2, 'Sesión dos');

        $luz = $this->enrollment($this->edition, 'Ramos', '11111111');
        $marco = $this->enrollment($this->edition, 'Diaz', '22222222', 'Marco');

        $this->store([
            $this->cell($luz, $first, 'present'),
            $this->cell($luz, $second, 'absent'),
            $this->cell($marco, $first, 'excused'),
            $this->cell($marco, $second, 'unmarked'),
        ])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status');

        $this->assertDatabaseCount('course_attendances', 4);

        foreach ([
            [$luz, $first, 'present'],
            [$luz, $second, 'absent'],
            [$marco, $first, 'excused'],
            [$marco, $second, 'unmarked'],
        ] as [$enrollment, $session, $status]) {
            $this->assertDatabaseHas('course_attendances', [
                'course_enrollment_id' => $enrollment->id,
                'course_session_id' => $session->id,
                'status' => $status,
                'marked_by' => $this->manager->id,
            ]);
        }

        // The authenticated actor is passed through to the service.
        $this->assertNotNull(CourseAttendance::query()->where('status', 'present')->sole()->marked_at);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Presente')
            ->assertSee('Justificado');
    }

    public function test_re_submitting_a_changed_cell_updates_the_same_attendance_row(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->store([$this->cell($enrollment, $session, 'present')])->assertRedirect($this->indexUrl());
        $this->store([$this->cell($enrollment, $session, 'absent')])->assertRedirect($this->indexUrl());

        // One row per session/enrollment pair, updated in place.
        $this->assertDatabaseCount('course_attendances', 1);
        $this->assertSame('absent', CourseAttendance::sole()->status);
    }

    public function test_a_session_from_another_edition_surfaces_as_a_visible_error_instead_of_a_server_error(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $otherEdition = $this->edition(CourseActivityType::Course, 'ED-ATT-002');
        $foreignSession = $this->courseSession($otherEdition, '2026-04-01', 1, 'Sesión de otra edición');

        // The same-edition rule belongs to CourseAttendanceService: the request
        // does not decide membership, so the domain exception must be translated
        // into a visible error and never become a 500.
        $this->store([$this->cell($enrollment, $foreignSession, 'present')])
            ->assertRedirect($this->indexUrl());

        $this->assertDatabaseCount('course_attendances', 0);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('must belong to the same edition');
    }

    public function test_an_invalid_status_from_the_service_surfaces_as_a_visible_error(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        // The set of valid statuses is owned by the service, so it is not
        // re-validated in the request: the service message must reach the user.
        $this->store([$this->cell($enrollment, $session, 'no-existe')])
            ->assertRedirect($this->indexUrl());

        $this->assertDatabaseCount('course_attendances', 0);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Attendance status is invalid');
    }

    public function test_an_enrollment_from_another_edition_is_reported_without_persisting_anything(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');

        $otherEdition = $this->edition(CourseActivityType::Course, 'ED-ATT-003');
        $foreignEnrollment = $this->enrollment($otherEdition, 'Ajeno', '99999999');

        // Mirror of the previous case: the same-edition rule is symmetric and
        // stays in the service, so either side of the pair is rejected.
        $this->store([$this->cell($foreignEnrollment, $session, 'present')])
            ->assertRedirect($this->indexUrl());

        $this->assertDatabaseCount('course_attendances', 0);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('must belong to the same edition');
    }

    public function test_unknown_or_incomplete_cells_are_reported_as_validation_errors(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->store([[
            'enrollment_id' => $enrollment->id,
            'session_id' => 999999,
            'status' => 'present',
        ]])->assertSessionHasErrors('cells.0.session_id');

        $this->store([[
            'enrollment_id' => 999999,
            'session_id' => $session->id,
            'status' => 'present',
        ]])->assertSessionHasErrors('cells.0.enrollment_id');

        $this->store([[
            'enrollment_id' => $enrollment->id,
            'session_id' => $session->id,
        ]])->assertSessionHasErrors('cells.0.status');

        $this->store([])->assertSessionHasErrors('cells');

        $this->assertDatabaseCount('course_attendances', 0);
    }

    public function test_talk_attendance_confirms_participation_and_the_matrix_shows_it(): void
    {
        $talk = $this->edition(CourseActivityType::Talk, 'ED-CHR-001');
        $session = $this->courseSession($talk, '2026-04-01', 1, 'Sesión de la charla');
        $confirmed = $this->enrollment($talk, 'Ramos', '11111111');
        $pending = $this->enrollment($talk, 'Diaz', '22222222', 'Marco');

        $before = $this->actingAs($this->manager)->get($this->indexUrl($talk));
        $before->assertOk()
            ->assertSee('determina su participación')
            ->assertSee('Participación pendiente');

        $this->store([
            $this->cell($confirmed, $session, 'present'),
            $this->cell($pending, $session, 'unmarked'),
        ], $talk)
            ->assertRedirect($this->indexUrl($talk));

        // The talk participation refresh stays in the service.
        $this->assertNotNull($confirmed->fresh()->participation_confirmed_at);
        $this->assertNull($pending->fresh()->participation_confirmed_at);

        $after = $this->actingAs($this->manager)->get($this->indexUrl($talk));
        $after->assertOk()
            ->assertSee('Participación confirmada')
            ->assertSee('Participación pendiente');

        $this->assertSame(1, substr_count($after->getContent(), 'Participación confirmada'));
    }

    public function test_talk_participation_is_confirmed_by_excused_and_late_statuses(): void
    {
        $talk = $this->edition(CourseActivityType::Talk, 'ED-CHR-002');
        $session = $this->courseSession($talk, '2026-04-01', 1, 'Sesión de la charla');
        $excused = $this->enrollment($talk, 'Ramos', '11111111');
        $late = $this->enrollment($talk, 'Diaz', '22222222', 'Marco');
        $absent = $this->enrollment($talk, 'Paz', '33333333', 'Elena');

        $this->store([
            $this->cell($excused, $session, 'excused'),
            $this->cell($late, $session, 'late'),
            $this->cell($absent, $session, 'absent'),
        ], $talk)->assertRedirect($this->indexUrl($talk));

        $this->assertNotNull($excused->fresh()->participation_confirmed_at);
        $this->assertNotNull($late->fresh()->participation_confirmed_at);
        $this->assertNull($absent->fresh()->participation_confirmed_at);
    }

    public function test_talk_participation_is_cleared_when_no_participating_status_remains(): void
    {
        $talk = $this->edition(CourseActivityType::Talk, 'ED-CHR-003');
        $session = $this->courseSession($talk, '2026-04-01', 1, 'Sesión de la charla');
        $enrollment = $this->enrollment($talk, 'Ramos', '11111111');

        $this->store([$this->cell($enrollment, $session, 'present')], $talk)
            ->assertRedirect($this->indexUrl($talk));

        $this->assertNotNull($enrollment->fresh()->participation_confirmed_at);

        // The bulk submit drives the service's participation refresh in both
        // directions; the controller never recomputes it.
        $this->store([$this->cell($enrollment, $session, 'absent')], $talk)
            ->assertRedirect($this->indexUrl($talk));

        $this->assertNull($enrollment->fresh()->participation_confirmed_at);
        $this->assertDatabaseCount('course_attendances', 1);
    }

    public function test_course_attendance_is_presented_as_informational(): void
    {
        $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('la asistencia es informativa')
            // Participation only exists for talks.
            ->assertDontSee('Participación confirmada')
            ->assertDontSee('Participación pendiente');
    }

    public function test_the_matrix_shows_an_empty_state_when_the_edition_has_no_sessions(): void
    {
        $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('todavía no tiene sesiones registradas')
            ->assertDontSee('Guardar asistencia')
            ->assertDontSee('cells[');
    }

    public function test_the_matrix_shows_an_empty_state_when_the_edition_has_no_enrollments(): void
    {
        $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Todavía no hay participantes inscritos en esta edición.')
            ->assertDontSee('Guardar asistencia')
            ->assertDontSee('cells[');
    }
}
