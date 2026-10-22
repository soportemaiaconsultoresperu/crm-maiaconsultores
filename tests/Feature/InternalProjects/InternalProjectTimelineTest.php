<?php

namespace Tests\Feature\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Http\Controllers\InternalProjects\InternalProjectTimelineController;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\User;
use App\Services\InternalProjects\InternalProjectTimelineBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Internal project timeline / Gantt (odd/tasks/internal-projects.md, T3: S4.2).
 * Phases as summary rows, tasks as bars between start and due date over a
 * Lima-dated day scale, today line, months header, milestones as diamonds.
 */
class InternalProjectTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $responsible;

    private User $viewer;

    private User $outsider;

    private InternalProject $project;

    private InternalProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerRoutesIfMissing();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->responsible = $this->userWithRole('vendedor');
        $this->viewer = $this->userWithRole('vendedor');
        $this->outsider = $this->userWithRole('vendedor');

        $this->project = InternalProject::factory()->create([
            'name' => 'Nuevo CRM',
            'color' => '#7c3aed',
            'starts_on' => '2026-11-04',
            'ends_on' => '2026-11-20',
            'responsible_user_id' => $this->responsible->id,
            'created_by' => $this->responsible->id,
            'audience_type' => InternalProject::AUDIENCE_USERS,
        ]);
        $this->project->members()->attach($this->responsible->id);
        $this->project->audienceUsers()->attach($this->viewer->id);
        $this->phase = InternalProjectPhase::factory()->for($this->project, 'project')->create(['name' => 'Desarrollo', 'sort' => 1]);
    }

    /**
     * P2 owns routes/web.php; the stubs keep these tests independent of it.
     */
    private function registerRoutesIfMissing(): void
    {
        if (! Route::has('internal-projects.timeline')) {
            Route::middleware(['web', 'auth', 'active'])
                ->get('internal-projects/{internalProject}/timeline', [InternalProjectTimelineController::class, 'show'])
                ->name('internal-projects.timeline');
        }

        if (! Route::has('internal-projects.show')) {
            Route::middleware(['web', 'auth', 'active'])
                ->get('internal-projects/{internalProject}', fn () => 'show')
                ->name('internal-projects.show');
        }

        if (! Route::has('internal-projects.index')) {
            Route::middleware(['web', 'auth', 'active'])
                ->get('internal-projects', fn () => 'index')
                ->name('internal-projects.index');
        }

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = [], ?InternalProjectPhase $phase = null): InternalProjectTask
    {
        return InternalProjectTask::factory()->for($phase ?? $this->phase, 'phase')->create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?InternalProject $project = null, string $nowUtc = '2026-11-10 15:00:00'): array
    {
        $project = ($project ?? $this->project)->fresh(['phases.tasks.responsible']);

        return app(InternalProjectTimelineBuilder::class)->build($project, CarbonImmutable::parse($nowUtc, 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @return array<string, mixed>
     */
    private function row(array $timeline, string $type, int $id): array
    {
        foreach ($timeline['rows'] as $row) {
            if ($row['type'] === $type && $row['id'] === $id) {
                return $row;
            }
        }

        $this->fail("Row {$type} #{$id} not found.");
    }

    // ---- builder ------------------------------------------------------------

    public function test_range_is_padded_to_monday_and_sunday_weeks(): void
    {
        $this->task(['starts_on' => '2026-11-05', 'due_on' => '2026-11-18']);

        $timeline = $this->build();

        // Project 2026-11-04 (Wed) .. 2026-11-20 (Fri) → Mon 02/11 .. Sun 22/11.
        $this->assertSame('2026-11-02', $timeline['range']['start']);
        $this->assertSame('2026-11-22', $timeline['range']['end']);
        $this->assertSame(21, $timeline['range']['days']);
        $this->assertFalse($timeline['range']['truncated']);
        $this->assertSame([0, 7, 14], array_column($timeline['weeks'], 'offset'));
        $this->assertSame(['02/11', '09/11', '16/11'], array_column($timeline['weeks'], 'label'));
    }

    public function test_range_spans_task_dates_outside_the_project_dates(): void
    {
        $this->task(['starts_on' => '2026-10-28', 'due_on' => '2026-12-03']);

        $timeline = $this->build();

        $this->assertSame('2026-10-26', $timeline['range']['start']);
        $this->assertSame('2026-12-06', $timeline['range']['end']);
        $this->assertSame(
            [['label' => 'oct 2026', 'offset' => 0, 'days' => 6], ['label' => 'nov 2026', 'offset' => 6, 'days' => 30], ['label' => 'dic 2026', 'offset' => 36, 'days' => 6]],
            array_map(fn (array $m): array => ['label' => $m['label'], 'offset' => $m['offset'], 'days' => $m['days']], $timeline['months']),
        );
    }

    public function test_range_is_capped_at_eighteen_months(): void
    {
        $this->task(['starts_on' => '2026-11-05', 'due_on' => '2029-01-10']);

        $timeline = $this->build();

        $this->assertTrue($timeline['range']['truncated']);
        $this->assertLessThanOrEqual(18 * 31 + 7, $timeline['range']['days']);
        $this->assertSame('2026-11-02', $timeline['range']['start']);
    }

    public function test_task_offset_and_length_are_inclusive_days(): void
    {
        $task = $this->task(['starts_on' => '2026-11-05', 'due_on' => '2026-11-07']);
        $single = $this->task(['starts_on' => '2026-11-09', 'due_on' => '2026-11-09']);

        $timeline = $this->build();
        $row = $this->row($timeline, 'task', $task->id);

        $this->assertSame(3, $row['offset']);
        $this->assertSame(3, $row['length']);
        $this->assertSame(1, $this->row($timeline, 'task', $single->id)['length']);
        $this->assertEqualsWithDelta(3 / 21 * 100, $row['left_pct'], 0.01);
        $this->assertEqualsWithDelta(3 / 21 * 100, $row['width_pct'], 0.01);
        $this->assertSame('05/11/2026', $row['start_label']);
        $this->assertSame('07/11/2026', $row['end_label']);
    }

    public function test_overdue_and_done_flags_follow_lima_midnight(): void
    {
        $task = $this->task(['starts_on' => '2026-11-05', 'due_on' => '2026-11-15', 'status' => TaskStatus::InProgress]);
        $done = $this->task(['starts_on' => '2026-11-05', 'due_on' => '2026-11-10', 'status' => TaskStatus::Done]);

        // 03:00 UTC on the 16th is still 22:00 of the 15th in Lima.
        $before = $this->build(null, '2026-11-16 03:00:00');
        $this->assertFalse($this->row($before, 'task', $task->id)['overdue']);
        $this->assertSame('in-progress', $this->row($before, 'task', $task->id)['state']);
        $this->assertSame('2026-11-15', $before['today']['date']);

        // 05:00 UTC on the 16th is Lima midnight: the due date has passed.
        $after = $this->build(null, '2026-11-16 05:00:00');
        $this->assertTrue($this->row($after, 'task', $task->id)['overdue']);
        $this->assertSame('overdue', $this->row($after, 'task', $task->id)['state']);
        $this->assertSame(14, $after['today']['offset']);
        $this->assertTrue($after['today']['in_range']);

        $doneRow = $this->row($after, 'task', $done->id);
        $this->assertTrue($doneRow['done']);
        $this->assertFalse($doneRow['overdue']);
        $this->assertSame('done', $doneRow['state']);
    }

    public function test_blocked_and_pending_states(): void
    {
        $blocked = $this->task(['status' => TaskStatus::Blocked, 'starts_on' => '2026-11-05', 'due_on' => '2026-11-18']);
        $pending = $this->task(['status' => TaskStatus::Pending, 'starts_on' => '2026-11-05', 'due_on' => '2026-11-18']);

        $timeline = $this->build();

        $this->assertSame('blocked', $this->row($timeline, 'task', $blocked->id)['state']);
        $this->assertSame('Bloqueada', $this->row($timeline, 'task', $blocked->id)['status_label']);
        $this->assertSame('pending', $this->row($timeline, 'task', $pending->id)['state']);
    }

    public function test_milestone_marks_its_due_date(): void
    {
        $owner = $this->userWithRole('vendedor');
        $owner->update(['name' => 'Ana Torres']);
        $milestone = $this->task(['title' => 'Salida a producción', 'starts_on' => '2026-11-12', 'due_on' => '2026-11-12', 'is_milestone' => true, 'responsible_user_id' => $owner->id]);

        $row = $this->row($this->build(), 'task', $milestone->id);

        $this->assertTrue($row['milestone']);
        $this->assertSame(10, $row['marker_offset']);
        $this->assertSame('Salida a producción', $row['title']);
        $this->assertSame('Ana Torres', $row['responsible']);
    }

    public function test_phase_span_comes_from_its_tasks_and_reports_percent(): void
    {
        $this->phase->update(['starts_on' => '2026-11-02', 'ends_on' => '2026-11-22']);
        $this->task(['starts_on' => '2026-11-05', 'due_on' => '2026-11-09', 'status' => TaskStatus::Done, 'weight' => 1]);
        $this->task(['starts_on' => '2026-11-08', 'due_on' => '2026-11-13', 'weight' => 3]);
        $empty = InternalProjectPhase::factory()->for($this->project, 'project')->create([
            'name' => 'Pruebas', 'sort' => 2, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-19',
        ]);
        $undated = InternalProjectPhase::factory()->for($this->project, 'project')->create(['name' => 'Lanzamiento', 'sort' => 3]);

        $timeline = $this->build();
        $phase = $this->row($timeline, 'phase', $this->phase->id);

        $this->assertSame('2026-11-05', $phase['start']);
        $this->assertSame('2026-11-13', $phase['end']);
        $this->assertSame(3, $phase['offset']);
        $this->assertSame(9, $phase['length']);
        $this->assertSame(25, $phase['percent']);
        $this->assertSame(2, $phase['task_count']);

        $emptyRow = $this->row($timeline, 'phase', $empty->id);
        $this->assertSame('2026-11-16', $emptyRow['start']);
        $this->assertSame(0, $emptyRow['percent']);
        $this->assertFalse($this->row($timeline, 'phase', $undated->id)['has_bar']);

        // Phase rows precede their tasks, in phase order.
        $this->assertSame(['phase', 'task', 'task', 'phase', 'phase'], array_column($timeline['rows'], 'type'));
    }

    public function test_empty_project_shows_the_current_lima_month(): void
    {
        $project = InternalProject::factory()->create(['starts_on' => null, 'ends_on' => null, 'responsible_user_id' => $this->responsible->id]);

        $timeline = $this->build($project, '2026-10-22 15:00:00');

        // October 2026: Thu 01 .. Sat 31 → Mon 28/09 .. Sun 01/11.
        $this->assertSame('2026-09-28', $timeline['range']['start']);
        $this->assertSame('2026-11-01', $timeline['range']['end']);
        $this->assertSame([], $timeline['rows']);
        $this->assertTrue($timeline['today']['in_range']);
        $this->assertSame(24, $timeline['today']['offset']);
    }

    // ---- HTTP ---------------------------------------------------------------

    public function test_team_member_sees_the_gantt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));
        $task = $this->task(['title' => 'Construir API', 'starts_on' => '2026-11-05', 'due_on' => '2026-11-12', 'status' => TaskStatus::InProgress]);
        $milestone = $this->task(['title' => 'Demo gerencia', 'starts_on' => '2026-11-18', 'due_on' => '2026-11-18', 'is_milestone' => true]);

        $response = $this->actingAs($this->responsible)->get(route('internal-projects.timeline', $this->project));

        $response->assertOk()
            ->assertSee('Nuevo CRM')
            ->assertSee('data-testid="ip-gantt-today"', false)
            ->assertSee('data-testid="ip-gantt-legend"', false)
            ->assertSee('data-testid="ip-gantt-bar-'.$task->id.'"', false)
            ->assertSee('data-testid="ip-gantt-milestone-'.$milestone->id.'"', false)
            ->assertSee('data-testid="ip-gantt-phase-'.$this->phase->id.'"', false)
            ->assertSee('nov 2026')
            ->assertSee('Construir API')
            ->assertSee('05/11/2026')
            ->assertSee(route('internal-projects.show', $this->project).'#task-'.$task->id, false)
            ->assertSee('Hoy');
    }

    public function test_audience_viewer_sees_the_gantt(): void
    {
        $this->task(['title' => 'Diseñar pantallas']);

        $this->actingAs($this->viewer)
            ->get(route('internal-projects.timeline', $this->project))
            ->assertOk()
            ->assertSee('Diseñar pantallas');
    }

    public function test_outsider_gets_not_found(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('internal-projects.timeline', $this->project))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('internal-projects.timeline', $this->project))->assertRedirect(route('login'));
    }
}
