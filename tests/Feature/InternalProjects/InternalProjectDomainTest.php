<?php

namespace Tests\Feature\InternalProjects;

use App\Enums\InternalProjects\ProjectStatus;
use App\Enums\InternalProjects\TaskStatus;
use App\Exceptions\InternalProjects\InvalidInternalProjectTask;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\User;
use App\Services\InternalProjects\InternalProjectProgressService;
use App\Services\InternalProjects\InternalProjectTaskService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Internal projects domain (odd/tasks/internal-projects.md, T1: S2, S3, S5).
 * Project → phases → tasks, weighted progress by task status, Lima-dated
 * overdue tasks, audited status changes and "team edits, others only view".
 */
class InternalProjectDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $responsible;

    private User $member;

    private User $supervisor;

    private User $outsider;

    private InternalProject $project;

    private InternalProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->responsible = $this->userWithRole('vendedor');
        $this->member = $this->userWithRole('vendedor');
        $this->supervisor = $this->userWithRole('supervisor');
        $this->outsider = $this->userWithRole('vendedor');

        $this->project = InternalProject::factory()->create([
            'responsible_user_id' => $this->responsible->id,
            'created_by' => $this->responsible->id,
            'audience_type' => InternalProject::AUDIENCE_USERS,
        ]);
        $this->project->members()->attach([$this->responsible->id, $this->member->id]);
        $this->phase = InternalProjectPhase::factory()->for($this->project, 'project')->create(['name' => 'Desarrollo']);
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
     * @return list<int>
     */
    private function visibleIds(User $user): array
    {
        return InternalProject::query()->visibleTo($user)->pluck('id')->all();
    }

    // ---- S2 structure -------------------------------------------------------

    public function test_project_structure_casts_and_labels(): void
    {
        $task = $this->task(['weight' => 3, 'is_milestone' => true]);

        $this->assertSame(ProjectStatus::Planned, $this->project->fresh()->status);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertSame($this->project->id, $task->project->id);
        $this->assertSame(['Desarrollo'], $this->project->phases->pluck('name')->all());
        $this->assertTrue($task->is_milestone);
        $this->assertSame('En pausa', ProjectStatus::OnHold->label());
        $this->assertSame('Bloqueada', TaskStatus::Blocked->label());
        $this->assertSame('text-bg-success', TaskStatus::Done->badgeClass());
        $this->assertSame(
            ['Planificado', 'En curso', 'En pausa', 'Terminado', 'Cancelado'],
            array_values(ProjectStatus::labels()),
        );
        $this->assertSame(['Pendiente', 'En curso', 'Bloqueada', 'Terminada'], array_values(TaskStatus::labels()));
    }

    public function test_project_code_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        InternalProject::factory()->create(['code' => $this->project->code]);
    }

    public function test_phases_are_ordered_by_sort(): void
    {
        InternalProjectPhase::factory()->for($this->project, 'project')->create(['name' => 'Análisis', 'sort' => -1]);
        InternalProjectPhase::factory()->for($this->project, 'project')->create(['name' => 'Pruebas', 'sort' => 5]);

        $this->assertSame(['Análisis', 'Desarrollo', 'Pruebas'], $this->project->phases()->get()->pluck('name')->all());
    }

    // ---- S5 visibility ------------------------------------------------------

    public function test_audience_all_is_visible_to_everyone(): void
    {
        $this->project->update(['audience_type' => InternalProject::AUDIENCE_ALL]);

        $this->assertContains($this->project->id, $this->visibleIds($this->outsider));
        $this->assertTrue($this->project->isVisibleTo($this->outsider));
    }

    public function test_audience_roles_limits_visibility(): void
    {
        $this->project->update(['audience_type' => InternalProject::AUDIENCE_ROLES]);
        $this->project->audienceRoles()->attach(Role::findByName('supervisor')->id);

        $this->assertTrue($this->project->isVisibleTo($this->supervisor));
        $this->assertFalse($this->project->isVisibleTo($this->outsider));
        $this->assertNotContains($this->project->id, $this->visibleIds($this->outsider));
    }

    public function test_audience_users_limits_visibility(): void
    {
        $this->project->audienceUsers()->attach($this->supervisor->id);

        $this->assertTrue($this->project->isVisibleTo($this->supervisor));
        $this->assertFalse($this->project->isVisibleTo($this->outsider));
    }

    public function test_roles_audience_rows_do_not_leak_when_audience_is_users(): void
    {
        $this->project->audienceRoles()->attach(Role::findByName('vendedor')->id);

        $this->assertFalse($this->project->isVisibleTo($this->outsider));
    }

    public function test_team_creator_and_admin_always_see_it(): void
    {
        $creator = $this->userWithRole('vendedor');
        $mine = InternalProject::factory()->create([
            'created_by' => $creator->id,
            'responsible_user_id' => $this->responsible->id,
            'audience_type' => InternalProject::AUDIENCE_USERS,
        ]);

        $this->assertTrue($this->project->isVisibleTo($this->member));
        $this->assertTrue($this->project->isVisibleTo($this->responsible));
        $this->assertTrue($this->project->isVisibleTo($this->admin));
        $this->assertTrue($mine->isVisibleTo($creator));
        $this->assertFalse($this->project->isVisibleTo($this->outsider));
        $this->assertEqualsCanonicalizing(
            [$this->project->id, $mine->id],
            $this->visibleIds($this->admin),
        );
    }

    // ---- S5 policy ----------------------------------------------------------

    public function test_policy_abilities_team_edits_others_only_view(): void
    {
        $this->project->audienceUsers()->attach($this->supervisor->id);

        foreach ([$this->admin, $this->responsible, $this->member, $this->supervisor, $this->outsider] as $user) {
            $this->assertTrue($user->can('viewAny', InternalProject::class));
            $this->assertTrue($user->can('create', InternalProject::class));
        }

        $this->assertTrue($this->supervisor->can('view', $this->project));
        $this->assertFalse($this->outsider->can('view', $this->project));

        $this->assertTrue($this->admin->can('manageWork', $this->project));
        $this->assertTrue($this->responsible->can('manageWork', $this->project));
        $this->assertTrue($this->member->can('manageWork', $this->project));
        $this->assertFalse($this->supervisor->can('manageWork', $this->project));
        $this->assertFalse($this->outsider->can('manageWork', $this->project));

        foreach (['update', 'delete'] as $ability) {
            $this->assertTrue($this->admin->can($ability, $this->project));
            $this->assertTrue($this->responsible->can($ability, $this->project));
            $this->assertFalse($this->member->can($ability, $this->project));
            $this->assertFalse($this->supervisor->can($ability, $this->project));
        }
    }

    public function test_creator_who_is_not_responsible_does_not_manage_settings(): void
    {
        $creator = $this->userWithRole('vendedor');
        $this->project->update(['created_by' => $creator->id]);

        $this->assertTrue($creator->can('view', $this->project));
        $this->assertFalse($creator->can('update', $this->project));
        $this->assertFalse($creator->can('manageWork', $this->project));
    }

    // ---- S3 progress ----------------------------------------------------------

    public function test_progress_is_zero_without_tasks(): void
    {
        $progress = app(InternalProjectProgressService::class)->projectProgress($this->project);

        $this->assertSame(0, $progress['percent']);
        $this->assertSame(0, $progress['total']);
        $this->assertSame(0, app(InternalProjectProgressService::class)->phaseProgress($this->phase)['percent']);
    }

    public function test_project_progress_uses_weights_of_done_tasks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00', 'America/Lima'));
        $other = InternalProjectPhase::factory()->for($this->project, 'project')->create();

        $this->task(['weight' => 3, 'status' => TaskStatus::Done]);
        $this->task(['weight' => 1, 'status' => TaskStatus::InProgress]);
        $this->task(['weight' => 2, 'status' => TaskStatus::Blocked, 'starts_on' => '2026-10-01', 'due_on' => '2026-10-10']);
        $this->task(['weight' => 2, 'status' => TaskStatus::Pending], $other);
        $this->task(['weight' => 5, 'status' => TaskStatus::Done])->delete();

        $progress = app(InternalProjectProgressService::class)->projectProgress($this->project);

        // 3 done of 3+1+2+2 = 8 → 37.5 % → 37 (floored).
        $this->assertSame(37, $progress['percent']);
        $this->assertSame(4, $progress['total']);
        $this->assertSame(1, $progress['terminadas']);
        $this->assertSame(1, $progress['en_curso']);
        $this->assertSame(1, $progress['bloqueadas']);
        $this->assertSame(1, $progress['pendientes']);
        $this->assertSame(1, $progress['atrasadas']);
    }

    public function test_phase_progress_and_preloaded_phases_progress(): void
    {
        $other = InternalProjectPhase::factory()->for($this->project, 'project')->create();
        $this->task(['weight' => 1, 'status' => TaskStatus::Done]);
        $this->task(['weight' => 1, 'status' => TaskStatus::Pending]);
        $this->task(['weight' => 4, 'status' => TaskStatus::Done], $other);

        $service = app(InternalProjectProgressService::class);

        $this->assertSame(50, $service->phaseProgress($this->phase)['percent']);
        $this->assertSame(100, $service->phaseProgress($other)['percent']);

        $project = InternalProject::query()->with(['phases', 'tasks'])->findOrFail($this->project->id);
        DB::enableQueryLog();
        $byPhase = $service->phasesProgress($project);
        $overall = $service->projectProgress($project);
        $milestones = $service->nextMilestones($project);
        $this->assertSame([], DB::getQueryLog(), 'Preloaded relations must not trigger queries.');
        DB::disableQueryLog();

        $this->assertSame(50, $byPhase[$this->phase->id]['percent']);
        $this->assertSame(100, $byPhase[$other->id]['percent']);
        $this->assertSame(83, $overall['percent']);
        $this->assertCount(0, $milestones);
    }

    public function test_all_done_is_one_hundred_percent(): void
    {
        $this->task(['weight' => 2, 'status' => TaskStatus::Done]);
        $this->task(['weight' => 7, 'status' => TaskStatus::Done]);

        $this->assertSame(100, app(InternalProjectProgressService::class)->projectProgress($this->project)['percent']);
    }

    public function test_next_milestones_are_open_ones_ordered_by_due_date(): void
    {
        $this->task(['title' => 'Lanzamiento', 'is_milestone' => true, 'starts_on' => '2026-12-01', 'due_on' => '2026-12-20']);
        $this->task(['title' => 'Demo', 'is_milestone' => true, 'starts_on' => '2026-11-01', 'due_on' => '2026-11-05']);
        $this->task(['title' => 'Cerrado', 'is_milestone' => true, 'status' => TaskStatus::Done, 'due_on' => '2026-10-30']);
        $this->task(['title' => 'Normal', 'is_milestone' => false, 'due_on' => '2026-10-25']);
        $this->task(['title' => 'Aprobación', 'is_milestone' => true, 'starts_on' => '2026-11-01', 'due_on' => '2026-11-10']);
        $this->task(['title' => 'Piloto', 'is_milestone' => true, 'starts_on' => '2027-01-01', 'due_on' => '2027-01-10']);

        $service = app(InternalProjectProgressService::class);

        $this->assertSame(['Demo', 'Aprobación', 'Lanzamiento'], $service->nextMilestones($this->project)->pluck('title')->all());
        $this->assertSame(['Demo'], $service->nextMilestones($this->project, 1)->pluck('title')->all());

        $preloaded = InternalProject::query()->with('tasks')->findOrFail($this->project->id);
        $this->assertSame(['Demo', 'Aprobación', 'Lanzamiento'], $service->nextMilestones($preloaded)->pluck('title')->all());
    }

    // ---- S3 overdue at Lima midnight ---------------------------------------------

    public function test_task_becomes_overdue_at_lima_midnight(): void
    {
        $task = $this->task(['starts_on' => '2026-10-15', 'due_on' => '2026-10-21']);

        // 23:59:59 in Lima on the due date is 04:59:59 UTC the next day: not late yet.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 04:59:59', 'UTC'));
        $this->assertFalse($task->isOverdue());
        $this->assertSame(0, InternalProjectTask::query()->overdue()->count());

        // Lima midnight of the following day: late.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 05:00:00', 'UTC'));
        $this->assertTrue($task->isOverdue());
        $this->assertSame([$task->id], InternalProjectTask::query()->overdue()->pluck('id')->all());
    }

    public function test_done_task_is_never_overdue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 12:00', 'UTC'));
        $task = $this->task(['status' => TaskStatus::Done, 'starts_on' => '2026-10-01', 'due_on' => '2026-10-02']);

        $this->assertFalse($task->isOverdue());
        $this->assertSame(0, InternalProjectTask::query()->overdue()->count());
    }

    // ---- S3 status changes ------------------------------------------------------

    public function test_change_status_sets_completed_at_and_records_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'UTC'));
        $task = $this->task();
        $service = app(InternalProjectTaskService::class);

        $service->changeStatus($task, TaskStatus::Done, $this->member);

        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertSame('2026-10-22 10:00:00', $task->fresh()->completed_at->toDateTimeString());
        $this->assertSame($this->member->id, $task->fresh()->updated_by);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'UTC'));
        $service->changeStatus($task, TaskStatus::InProgress, $this->responsible);

        $this->assertNull($task->fresh()->completed_at);
        $history = $task->statusChanges()->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame(TaskStatus::Pending, $history[0]->from_status);
        $this->assertSame(TaskStatus::Done, $history[0]->to_status);
        $this->assertSame($this->member->id, $history[0]->user_id);
        $this->assertSame('2026-10-22 10:00:00', $history[0]->changed_at->toDateTimeString());
        $this->assertSame(TaskStatus::Done, $history[1]->from_status);
        $this->assertSame($this->responsible->id, $history[1]->user->id);
    }

    public function test_change_to_same_status_records_nothing(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress]);

        app(InternalProjectTaskService::class)->changeStatus($task, TaskStatus::InProgress, $this->member);

        $this->assertSame(0, $task->statusChanges()->count());
    }

    public function test_change_status_runs_in_a_transaction_on_the_fresh_row(): void
    {
        $task = $this->task();
        $stale = InternalProjectTask::query()->findOrFail($task->id);
        app(InternalProjectTaskService::class)->changeStatus($task, TaskStatus::Blocked, $this->member);

        $levels = [];
        InternalProjectTask::updating(function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        // The stale instance still says Pendiente; the locked row says Bloqueada.
        app(InternalProjectTaskService::class)->changeStatus($stale, TaskStatus::Done, $this->member);

        $this->assertNotEmpty($levels);
        $this->assertGreaterThan(0, min($levels));
        $last = $task->statusChanges()->latest('id')->firstOrFail();
        $this->assertSame(TaskStatus::Blocked, $last->from_status);
        $this->assertSame(TaskStatus::Done, $stale->status);
    }

    // ---- Task creation and update invariants -------------------------------------

    public function test_create_task_in_project_records_initial_status(): void
    {
        $task = app(InternalProjectTaskService::class)->createTask($this->project, [
            'internal_project_phase_id' => $this->phase->id,
            'title' => 'Diseñar base de datos',
            'description' => 'Modelo de datos',
            'responsible_user_id' => $this->member->id,
            'starts_on' => '2026-11-01',
            'due_on' => '2026-11-01',
            'weight' => 2,
            'is_milestone' => true,
        ], $this->member);

        $this->assertSame($this->project->id, $task->internal_project_id);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(2, $task->weight);
        $this->assertSame($this->member->id, $task->created_by);
        $this->assertNull($task->completed_at);
        $this->assertSame(1, $task->statusChanges()->count());
        $this->assertNull($task->statusChanges()->first()->from_status);
    }

    public function test_create_task_done_sets_completed_at(): void
    {
        $task = app(InternalProjectTaskService::class)->createTask($this->project, [
            'internal_project_phase_id' => $this->phase->id,
            'title' => 'Ya hecho',
            'starts_on' => '2026-11-01',
            'due_on' => '2026-11-02',
            'status' => TaskStatus::Done->value,
        ], $this->member);

        $this->assertNotNull($task->completed_at);
        $this->assertSame(1, $task->weight);
    }

    public function test_phase_from_another_project_is_rejected(): void
    {
        $foreignPhase = InternalProjectPhase::factory()->create();

        try {
            app(InternalProjectTaskService::class)->createTask($this->project, [
                'internal_project_phase_id' => $foreignPhase->id,
                'title' => 'Intruso',
                'starts_on' => '2026-11-01',
                'due_on' => '2026-11-02',
            ], $this->member);
            $this->fail('A phase of another project must be rejected.');
        } catch (InvalidInternalProjectTask $e) {
            $this->assertSame(InvalidInternalProjectTask::PHASE_OUTSIDE_PROJECT, $e->reason());
            $this->assertSame('internal_project_phase_id', $e->field());
            $this->assertStringContainsString('fase', $e->getMessage());
        }

        $task = $this->task();
        $this->expectException(InvalidInternalProjectTask::class);
        app(InternalProjectTaskService::class)->updateTask($task, ['internal_project_phase_id' => $foreignPhase->id], $this->member);
    }

    public function test_due_before_start_is_rejected(): void
    {
        try {
            app(InternalProjectTaskService::class)->createTask($this->project, [
                'internal_project_phase_id' => $this->phase->id,
                'title' => 'Al revés',
                'starts_on' => '2026-11-05',
                'due_on' => '2026-11-04',
            ], $this->member);
            $this->fail('A due date before the start must be rejected.');
        } catch (InvalidInternalProjectTask $e) {
            $this->assertSame(InvalidInternalProjectTask::DUE_BEFORE_START, $e->reason());
            $this->assertSame('due_on', $e->field());
        }

        $task = $this->task(['starts_on' => '2026-11-01', 'due_on' => '2026-11-10']);
        try {
            app(InternalProjectTaskService::class)->updateTask($task, ['starts_on' => '2026-11-11'], $this->member);
            $this->fail('Moving the start after the due date must be rejected.');
        } catch (InvalidInternalProjectTask $e) {
            $this->assertSame(InvalidInternalProjectTask::DUE_BEFORE_START, $e->reason());
        }
        $this->assertSame('2026-11-01', $task->fresh()->starts_on->toDateString());
    }

    public function test_weight_must_be_at_least_one(): void
    {
        $this->expectException(InvalidInternalProjectTask::class);

        app(InternalProjectTaskService::class)->createTask($this->project, [
            'internal_project_phase_id' => $this->phase->id,
            'title' => 'Sin peso',
            'starts_on' => '2026-11-01',
            'due_on' => '2026-11-02',
            'weight' => 0,
        ], $this->member);
    }

    public function test_update_task_moves_phase_within_project_and_records_status(): void
    {
        $other = InternalProjectPhase::factory()->for($this->project, 'project')->create();
        $task = $this->task();

        $updated = app(InternalProjectTaskService::class)->updateTask($task, [
            'internal_project_phase_id' => $other->id,
            'title' => 'Renombrada',
            'status' => TaskStatus::Done->value,
        ], $this->responsible);

        $this->assertSame($other->id, $updated->internal_project_phase_id);
        $this->assertSame('Renombrada', $updated->title);
        $this->assertSame(TaskStatus::Done, $updated->status);
        $this->assertNotNull($updated->completed_at);
        $this->assertSame($this->responsible->id, $updated->updated_by);
        $this->assertSame(1, $updated->statusChanges()->count());
    }
}
