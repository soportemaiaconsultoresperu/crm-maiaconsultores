<?php

namespace Tests\Feature\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "Proyectos" calendar layer (odd/tasks/internal-projects.md, T4: S4.3).
 * Milestones and due dates of open tasks of visible projects appear on their
 * due day without an hour, painted with the project colour. Done tasks are
 * left out (they no longer need attention) except milestones, which always
 * show so the plan stays readable.
 */
class InternalProjectCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $responsible;

    private User $outsider;

    private InternalProject $project;

    private InternalProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        // The project detail page is built in parallel (T2); stub its route.
        if (! Route::has('internal-projects.show')) {
            Route::get('/internal-projects/{internal_project}', fn () => 'stub')->name('internal-projects.show');
            Route::getRoutes()->refreshNameLookups();
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));

        $this->responsible = User::factory()->create(['is_active' => true]);
        $this->responsible->assignRole('vendedor');
        $this->outsider = User::factory()->create(['is_active' => true]);
        $this->outsider->assignRole('vendedor');

        $this->project = InternalProject::factory()->private()->create([
            'name' => 'Nuevo portal',
            'code' => 'PI-PORTAL',
            'color' => '#16a34a',
            'responsible_user_id' => $this->responsible->id,
            'created_by' => $this->responsible->id,
        ]);
        $this->phase = InternalProjectPhase::factory()->create(['internal_project_id' => $this->project->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): InternalProjectTask
    {
        return InternalProjectTask::factory()->create(array_merge([
            'internal_project_phase_id' => $this->phase->id,
            'internal_project_id' => $this->project->id,
            'responsible_user_id' => $this->responsible->id,
        ], $attributes));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function calendar(User $user, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->get(route('calendar.index', array_merge(['anchor' => '2026-11-10'], $query)));
    }

    public function test_visible_user_sees_milestones_and_task_due_dates_under_todo_and_proyectos(): void
    {
        $milestone = $this->task(['title' => 'Salida a producción', 'is_milestone' => true, 'due_on' => '2026-11-20']);
        $task = $this->task(['title' => 'Maquetar inicio', 'due_on' => '2026-11-18', 'status' => TaskStatus::InProgress]);

        foreach (['', 'projects'] as $layer) {
            $month = $this->calendar($this->responsible, ['view' => 'month', 'layer' => $layer])->assertOk();
            $month->assertSee('◆ [PI-PORTAL] Salida a producción', false)
                ->assertSee('[PI-PORTAL] Maquetar inicio', false)
                ->assertSee(route('internal-projects.show', $this->project).'#task-'.$task->id, false)
                ->assertSee(route('internal-projects.show', $this->project).'#task-'.$milestone->id, false)
                ->assertSee('calendar-event-project_task', false)
                ->assertSee('#16a34a', false);

            $this->calendar($this->responsible, ['view' => 'list', 'layer' => $layer])->assertOk()
                ->assertSee('Hito')
                ->assertSee('Proyecto')
                ->assertSee('Nuevo portal')
                ->assertSee('En curso')
                ->assertSee($this->responsible->name);
        }
    }

    public function test_month_cell_holds_the_event_on_its_due_day(): void
    {
        $this->task(['title' => 'Maquetar inicio', 'due_on' => '2026-11-30']);

        $html = $this->calendar($this->responsible, ['view' => 'month', 'layer' => 'projects'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-date="2026-11-30"(?:(?!<td).)*Maquetar inicio/s',
            $html,
        );
    }

    public function test_project_events_print_no_midnight_hour(): void
    {
        $this->task(['title' => 'Maquetar inicio', 'due_on' => '2026-11-10']);

        foreach (['month', 'week', 'day', 'list'] as $view) {
            $this->calendar($this->responsible, ['view' => $view, 'layer' => 'projects'])->assertOk()
                ->assertSee('Maquetar inicio')
                ->assertDontSee('<strong>00:00</strong>', false)
                ->assertDontSee('<span class="text-secondary">00:00</span>', false)
                ->assertDontSee('10/11/2026 00:00', false);
        }
    }

    public function test_outsider_does_not_see_private_project_events(): void
    {
        $this->task(['title' => 'Maquetar inicio', 'due_on' => '2026-11-18']);

        foreach (['', 'projects'] as $layer) {
            $this->calendar($this->outsider, ['view' => 'list', 'layer' => $layer])->assertOk()
                ->assertDontSee('Maquetar inicio');
        }
    }

    public function test_commercial_internal_and_commercial_filters_exclude_project_events(): void
    {
        $this->task(['title' => 'Maquetar inicio', 'due_on' => '2026-11-18']);

        foreach ([['layer' => 'commercial'], ['layer' => 'internal'], ['status' => 'pending'], ['subject_type' => 'lead']] as $query) {
            $this->calendar($this->responsible, array_merge(['view' => 'list'], $query))->assertOk()
                ->assertDontSee('Maquetar inicio');
        }
    }

    public function test_soft_deleted_and_done_tasks_are_excluded_but_done_milestones_show(): void
    {
        $this->task(['title' => 'Tarea borrada', 'due_on' => '2026-11-18'])->delete();
        $this->task(['title' => 'Tarea terminada', 'due_on' => '2026-11-18', 'status' => TaskStatus::Done]);
        $this->task(['title' => 'Hito logrado', 'due_on' => '2026-11-05', 'status' => TaskStatus::Done, 'is_milestone' => true]);
        $this->task(['title' => 'Fuera de rango', 'due_on' => '2026-12-01']);

        $this->calendar($this->responsible, ['view' => 'list', 'layer' => 'projects'])->assertOk()
            ->assertDontSee('Tarea borrada')
            ->assertDontSee('Tarea terminada')
            ->assertDontSee('Fuera de rango')
            ->assertSee('Hito logrado');
    }

    public function test_overdue_follows_the_lima_calendar(): void
    {
        $this->task(['title' => 'Vence hoy en Lima', 'due_on' => '2026-11-09']);

        // 03:00 UTC on the 10th is still the 9th in Lima: not overdue yet.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC'));
        $this->calendar($this->responsible, ['view' => 'list', 'layer' => 'projects'])->assertOk()
            ->assertSee('Vence hoy en Lima')
            ->assertDontSee('calendar-event-overdue-task', false)
            ->assertDontSee('Atrasada');

        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC'));
        $this->calendar($this->responsible, ['view' => 'month', 'layer' => 'projects'])->assertOk()
            ->assertSee('calendar-event-overdue-task', false);
        $this->calendar($this->responsible, ['view' => 'list', 'layer' => 'projects'])->assertOk()
            ->assertSee('Atrasada');
    }
}
