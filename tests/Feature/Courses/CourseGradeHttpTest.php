<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\FinalResult;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\User;
use App\Services\Courses\CourseGradeService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6.d — authenticated grade matrix of one edition.
 *
 * The controller stays thin: CourseEditionPolicy authorizes (manageGrades for
 * the matrix and for the submission), StoreCourseGradeRequest validates only the
 * shape of the submitted cells, and CourseGradeService owns every grade rule —
 * the same-edition check, the talk rejection, the accepted value range through
 * CourseGradeCalculator, the upsert correction path and the enrollment result
 * recalculation. The matrix only renders what the domain decided.
 */
class CourseGradeHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['course-talks.view', 'course-talks.grades.manage']);

        $this->edition = $this->edition();
    }

    private function edition(CourseActivityType $type = CourseActivityType::Course, string $code = 'ED-GRD-001'): CourseEdition
    {
        $isTalk = $type === CourseActivityType::Talk;

        $activity = CourseActivity::factory()->create([
            'type' => $type,
            'code' => ($isTalk ? 'CHR-' : 'CUR-').$code,
            'name' => $isTalk ? 'Charla de notas' : 'Curso de notas',
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

    /** @return array{enrollment_id: int, session_id: int, grade: string} */
    private function cell(CourseEnrollment $enrollment, CourseSession $session, string $grade): array
    {
        return [
            'enrollment_id' => $enrollment->id,
            'session_id' => $session->id,
            'grade' => $grade,
        ];
    }

    private function indexUrl(?CourseEdition $edition = null): string
    {
        return route('course-talks.grades.index', $edition ?? $this->edition);
    }

    /** @param array<int, mixed> $cells */
    private function store(array $cells, ?CourseEdition $edition = null): TestResponse
    {
        $edition ??= $this->edition;

        return $this->actingAs($this->manager)
            ->from($this->indexUrl($edition))
            ->post(route('course-talks.grades.store', $edition), ['grades' => $cells]);
    }

    public function test_guests_are_redirected_to_login_from_the_grade_routes(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->get($this->indexUrl())->assertRedirect(route('login'));

        $this->post(route('course-talks.grades.store', $this->edition), [
            'grades' => [$this->cell($enrollment, $session, '15.00')],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_grades', 0);
    }

    public function test_users_without_grade_permission_neither_read_nor_write_the_matrix(): void
    {
        // The whole grade surface requires the grade permission: a module viewer
        // is not offered the link and both verbs fail closed with 403.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->actingAs($viewer)->get($this->indexUrl())->assertForbidden();

        $this->actingAs($viewer)->post(route('course-talks.grades.store', $this->edition), [
            'grades' => [$this->cell($enrollment, $session, '15.00')],
        ])->assertForbidden();

        $this->assertDatabaseCount('course_grades', 0);
        $this->assertNull($enrollment->fresh()->exact_average);

        $editionUrl = route('course-talks.grades.index', $this->edition);

        $this->actingAs($viewer)->get(route('course-talks.editions.show', $this->edition))
            ->assertOk()
            ->assertDontSee('href="'.$editionUrl.'"', false);

        $this->actingAs($this->manager)->get(route('course-talks.editions.show', $this->edition))
            ->assertOk()
            ->assertSee('href="'.$editionUrl.'"', false);
    }

    public function test_the_matrix_lists_every_enrollment_by_session_with_the_stored_grade_and_the_row_result(): void
    {
        // Created out of display order on purpose: the matrix orders by session
        // date, then sort order, not by creation.
        $second = $this->courseSession($this->edition, '2026-04-02', 2, 'Sesión dos');
        $first = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');

        $luz = $this->enrollment($this->edition, 'Ramos', '11111111');
        $marco = $this->enrollment($this->edition, 'Diaz', '22222222', 'Marco');

        app(CourseGradeService::class)->record($first, $luz, '12.50', null, $this->manager);

        $otherEdition = $this->edition(CourseActivityType::Course, 'ED-GRD-002');
        $this->courseSession($otherEdition, '2026-04-01', 1, 'Sesión de otra edición');
        $this->enrollment($otherEdition, 'Ajeno', '99999999');

        $response = $this->actingAs($this->manager)->get($this->indexUrl());

        $response->assertOk()
            ->assertSee('ED-GRD-001')
            ->assertSee('Curso de notas')
            ->assertSee('Ramos, Luz')
            ->assertSee('Diaz, Marco')
            // The stored grade is rendered as the current cell value.
            ->assertSee('value="12.50"', false)
            // The row surfaces the recalculation the domain performed.
            ->assertSee('Promedio exacto: 12.5000')
            ->assertSee('Promedio: 12.50')
            ->assertSee('Redondeado: 13')
            ->assertSee('Aprobado')
            ->assertDontSee('Sesión de otra edición')
            ->assertDontSee('Ajeno');

        $content = (string) $response->getContent();

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

    public function test_bulk_entry_persists_every_submitted_grade_with_the_authenticated_actor_and_recalculates_the_row(): void
    {
        $first = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $second = $this->courseSession($this->edition, '2026-04-02', 2, 'Sesión dos');

        $luz = $this->enrollment($this->edition, 'Ramos', '11111111');
        $marco = $this->enrollment($this->edition, 'Diaz', '22222222', 'Marco');

        $this->store([
            $this->cell($luz, $first, '12.00'),
            $this->cell($luz, $second, '14.00'),
            $this->cell($marco, $first, '10.50'),
            // An untouched cell submits no grade: it is skipped, never stored as 0.
            $this->cell($marco, $second, ''),
        ])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status', 'Notas actualizadas correctamente.');

        $this->assertDatabaseCount('course_grades', 3);

        foreach ([[$luz, $first, '12.00'], [$luz, $second, '14.00'], [$marco, $first, '10.50']] as [$enrollment, $session, $grade]) {
            $this->assertDatabaseHas('course_grades', [
                'course_enrollment_id' => $enrollment->id,
                'course_session_id' => $session->id,
                'grade' => $grade,
                'entered_by' => $this->manager->id,
            ]);
        }

        // Equal weights, decided by the domain calculator: (12 + 14) / 2 = 13.
        $this->assertSame('13.0000', $luz->fresh()->exact_average);
        $this->assertSame('13.00', $luz->fresh()->display_average);
        $this->assertSame(13, $luz->fresh()->rounded_result);
        $this->assertSame(FinalResult::Approved, $luz->fresh()->final_result);

        $this->assertSame('10.5000', $marco->fresh()->exact_average);
        $this->assertSame(11, $marco->fresh()->rounded_result);
        $this->assertSame(FinalResult::Participation, $marco->fresh()->final_result);

        // A submission that carries no grade says so instead of claiming a save.
        $this->store([$this->cell($marco, $second, '')])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status', 'No se enviaron notas para guardar.');

        $this->assertDatabaseCount('course_grades', 3);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Promedio exacto: 13.0000')
            ->assertSee('Redondeado: 11');
    }

    public function test_correcting_a_grade_updates_the_same_row_and_recalculates_the_result(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        // A description belongs to the grade row and belongs to another surface:
        // the matrix has no description field, so re-recording must not clear it.
        app(CourseGradeService::class)->record($session, $enrollment, '12.49', 'Examen final', $this->manager);

        $this->store([$this->cell($enrollment, $session, '15.00')])->assertRedirect($this->indexUrl());

        // The service upsert is the correction path: one row per session/enrollment.
        $this->assertDatabaseCount('course_grades', 1);
        $this->assertDatabaseHas('course_grades', [
            'course_enrollment_id' => $enrollment->id,
            'course_session_id' => $session->id,
            'grade' => '15.00',
            'description' => 'Examen final',
            'entered_by' => $this->manager->id,
        ]);

        $enrollment->refresh();
        $this->assertSame('15.0000', $enrollment->exact_average);
        $this->assertSame('15.00', $enrollment->display_average);
        $this->assertSame(15, $enrollment->rounded_result);
        $this->assertSame(FinalResult::Approved, $enrollment->final_result);
    }

    public function test_the_approval_boundary_drives_the_row_result(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $participation = $this->enrollment($this->edition, 'Ramos', '11111111');
        $approved = $this->enrollment($this->edition, 'Diaz', '22222222', 'Marco');

        $this->store([
            $this->cell($participation, $session, '12.49'),
            $this->cell($approved, $session, '12.50'),
        ])->assertRedirect($this->indexUrl());

        $this->assertSame('12.4900', $participation->fresh()->exact_average);
        $this->assertSame(12, $participation->fresh()->rounded_result);
        $this->assertSame(FinalResult::Participation, $participation->fresh()->final_result);

        $this->assertSame('12.5000', $approved->fresh()->exact_average);
        $this->assertSame(13, $approved->fresh()->rounded_result);
        $this->assertSame(FinalResult::Approved, $approved->fresh()->final_result);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Promedio exacto: 12.4900')
            ->assertSee('Promedio exacto: 12.5000')
            ->assertSee('Aprobado');
    }

    public function test_talk_editions_offer_no_grade_inputs_and_reject_a_submission_with_a_visible_error(): void
    {
        $talk = $this->edition(CourseActivityType::Talk, 'ED-CHR-GRD-001');
        $session = $this->courseSession($talk, '2026-04-01', 1, 'Sesión de la charla');
        $enrollment = $this->enrollment($talk, 'Ramos', '11111111');

        $this->actingAs($this->manager)->get($this->indexUrl($talk))
            ->assertOk()
            ->assertSee('las charlas no usan notas')
            // Talks show participation instead of grades.
            ->assertSee('Participación pendiente')
            ->assertDontSee('grades[')
            ->assertDontSee('Guardar notas');

        $this->store([$this->cell($enrollment, $session, '15.00')], $talk)
            ->assertRedirect($this->indexUrl($talk));

        $this->assertDatabaseCount('course_grades', 0);
        $this->assertNull($enrollment->fresh()->exact_average);

        // The domain rejection reaches the user with the service's own message.
        $this->actingAs($this->manager)->get($this->indexUrl($talk))
            ->assertOk()
            ->assertSee('Talk editions do not accept grades.');
    }

    public function test_domain_rejections_are_reported_as_visible_errors_instead_of_server_errors(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $otherEdition = $this->edition(CourseActivityType::Course, 'ED-GRD-003');
        $foreignSession = $this->courseSession($otherEdition, '2026-04-01', 1, 'Sesión de otra edición');

        // The same-edition rule belongs to CourseGradeService.
        $this->store([$this->cell($enrollment, $foreignSession, '15.00')])
            ->assertRedirect($this->indexUrl());

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('must belong to the same edition');

        // The accepted value range belongs to CourseGradeCalculator through the
        // service: the request never re-validates the value, so the domain
        // message must reach the user and the typed value must survive.
        $this->store([$this->cell($enrollment, $session, '25')])
            ->assertRedirect($this->indexUrl());

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Invalid grade [25].')
            ->assertSee('value="25"', false);

        $this->assertDatabaseCount('course_grades', 0);
    }

    public function test_unknown_cells_are_reported_as_validation_errors(): void
    {
        $session = $this->courseSession($this->edition, '2026-04-01', 1, 'Sesión uno');
        $enrollment = $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->store([[
            'enrollment_id' => $enrollment->id,
            'session_id' => 999999,
            'grade' => '15.00',
        ]])->assertSessionHasErrors('grades.0.session_id');

        $this->store([])->assertSessionHasErrors('grades');

        $this->assertDatabaseCount('course_grades', 0);
    }

    public function test_the_matrix_shows_empty_states_instead_of_a_form(): void
    {
        $this->enrollment($this->edition, 'Ramos', '11111111');

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('todavía no tiene sesiones registradas')
            ->assertDontSee('grades[')
            ->assertDontSee('Guardar notas');

        $emptyEdition = $this->edition(CourseActivityType::Course, 'ED-GRD-004');
        $this->courseSession($emptyEdition, '2026-04-01', 1, 'Sesión uno');

        $this->actingAs($this->manager)->get($this->indexUrl($emptyEdition))
            ->assertOk()
            ->assertSee('Todavía no hay participantes inscritos en este dictado.')
            ->assertDontSee('grades[')
            ->assertDontSee('Guardar notas');
    }
}
