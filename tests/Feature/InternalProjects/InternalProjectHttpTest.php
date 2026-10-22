<?php

namespace Tests\Feature\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Internal projects screens (odd/tasks/internal-projects.md, T2: S4.1, S5, S6).
 * Dashboard with progress, CRUD, phases/tasks/status by the team, read-only
 * audience, 404 for projects the user cannot see.
 */
class InternalProjectHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $responsible;

    private User $member;

    private User $viewer;

    private User $outsider;

    private InternalProject $project;

    private InternalProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->responsible = $this->userWithRole('vendedor');
        $this->member = $this->userWithRole('vendedor');
        $this->viewer = $this->userWithRole('vendedor');
        $this->outsider = $this->userWithRole('vendedor');

        $this->project = InternalProject::factory()->create([
            'name' => 'Nuevo CRM',
            'code' => 'CRM-01',
            'responsible_user_id' => $this->responsible->id,
            'created_by' => $this->responsible->id,
            'audience_type' => InternalProject::AUDIENCE_USERS,
        ]);
        $this->project->members()->attach([$this->responsible->id, $this->member->id]);
        $this->project->audienceUsers()->attach($this->viewer->id);
        $this->phase = InternalProjectPhase::factory()->for($this->project, 'project')->create(['name' => 'Desarrollo', 'sort' => 1]);
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
    private function task(array $attributes = []): InternalProjectTask
    {
        return InternalProjectTask::factory()->for($this->phase, 'phase')->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Portal de alumnos',
            'code' => 'PORTAL-1',
            'description' => 'Portal web',
            'color' => '#1d4ed8',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-01-31',
            'status' => 'planificado',
            'audience_type' => 'all',
        ], $overrides);
    }

    // ---- S4.1 dashboard -------------------------------------------------------

    public function test_dashboard_lists_only_visible_projects_with_progress_and_counts(): void
    {
        $this->task(['weight' => 3, 'status' => TaskStatus::Done]);
        $this->task(['weight' => 1, 'status' => TaskStatus::Blocked]);
        $this->task(['weight' => 1, 'status' => TaskStatus::InProgress, 'is_milestone' => true, 'title' => 'Hito beta', 'due_on' => '2027-01-10']);
        $hidden = InternalProject::factory()->private()->create(['name' => 'Proyecto secreto']);

        $response = $this->actingAs($this->viewer)->get(route('internal-projects.index'));

        $response->assertOk()
            ->assertSee('Nuevo CRM')
            ->assertSee('CRM-01')
            ->assertDontSee('Proyecto secreto')
            ->assertSee('data-testid="project-card-'.$this->project->id.'"', false)
            ->assertSee('data-testid="project-percent-'.$this->project->id.'">60 %', false)
            ->assertSee('Hito beta')
            ->assertSee('10/01/2027')
            ->assertSee(route('internal-projects.timeline', $this->project), false);
        $this->assertStringContainsString('data-count="total">3', $response->getContent());
        $this->assertStringContainsString('data-count="bloqueadas">1', $response->getContent());

        $this->actingAs($this->admin)->get(route('internal-projects.index'))->assertSee('Proyecto secreto');
        $this->actingAs($this->outsider)->get(route('internal-projects.index', ['status' => 'terminado']))
            ->assertOk()->assertDontSee('Proyecto secreto');
        $this->assertNotNull($hidden->id);
    }

    public function test_dashboard_filters_by_status(): void
    {
        InternalProject::factory()->create(['name' => 'Proyecto cerrado', 'status' => 'terminado']);

        $this->actingAs($this->outsider)->get(route('internal-projects.index', ['status' => 'terminado']))
            ->assertOk()->assertSee('Proyecto cerrado')->assertDontSee('Nuevo CRM');
    }

    // ---- create / edit ----------------------------------------------------------

    public function test_any_user_creates_a_project_becoming_responsible_and_member_with_suggested_phases(): void
    {
        $this->actingAs($this->outsider)->get(route('internal-projects.create'))
            ->assertOk()->assertSee('Crear fases sugeridas', false);

        $response = $this->actingAs($this->outsider)->post(route('internal-projects.store'), $this->projectPayload([
            'member_ids' => [$this->member->id],
            'create_default_phases' => '1',
        ]));

        $project = InternalProject::query()->where('code', 'PORTAL-1')->firstOrFail();
        $response->assertRedirect(route('internal-projects.show', $project));
        $this->assertSame($this->outsider->id, $project->responsible_user_id);
        $this->assertSame($this->outsider->id, $project->created_by);
        $this->assertEqualsCanonicalizing([$this->outsider->id, $this->member->id], $project->members()->pluck('users.id')->all());
        $this->assertSame(['Análisis', 'Diseño', 'Desarrollo', 'Pruebas', 'Lanzamiento'], $project->phases()->pluck('name')->all());
        $this->assertSame('#1d4ed8', $project->color);
    }

    public function test_create_with_chosen_responsible_and_without_phases_and_audience_by_roles(): void
    {
        $roleId = \Spatie\Permission\Models\Role::findByName('supervisor')->id;

        $this->actingAs($this->outsider)->post(route('internal-projects.store'), $this->projectPayload([
            'responsible_user_id' => $this->member->id,
            'audience_type' => 'roles',
            'role_ids' => [$roleId],
            'color' => 'custom',
            'color_custom' => '#ABCDEF',
        ]))->assertRedirect();

        $project = InternalProject::query()->where('code', 'PORTAL-1')->firstOrFail();
        $this->assertSame($this->member->id, $project->responsible_user_id);
        $this->assertEqualsCanonicalizing([$this->outsider->id, $this->member->id], $project->members()->pluck('users.id')->all());
        $this->assertSame(0, $project->phases()->count());
        $this->assertSame([$roleId], $project->audienceRoles()->pluck('roles.id')->all());
        $this->assertSame('#abcdef', $project->color);
    }

    public function test_validation_errors_are_in_spanish(): void
    {
        $this->actingAs($this->outsider)->from(route('internal-projects.create'))
            ->post(route('internal-projects.store'), $this->projectPayload([
                'name' => '',
                'code' => 'CRM-01',
                'starts_on' => '2027-02-01',
                'ends_on' => '2027-01-01',
                'audience_type' => 'users',
            ]))
            ->assertRedirect(route('internal-projects.create'))
            ->assertSessionHasErrors(['name', 'code', 'ends_on', 'user_ids']);

        $errors = session('errors');
        $this->assertStringContainsString('obligatorio', $errors->first('name'));
        $this->assertStringContainsString('código', $errors->first('code'));
        $this->assertSame(1, InternalProject::query()->count());
    }

    public function test_only_responsible_or_admin_edits_settings_members_audience_and_deletes(): void
    {
        foreach ([$this->member, $this->viewer] as $user) {
            $this->actingAs($user)->get(route('internal-projects.edit', $this->project))->assertForbidden();
            $this->actingAs($user)->put(route('internal-projects.update', $this->project), $this->projectPayload(['code' => 'CRM-01']))->assertForbidden();
            $this->actingAs($user)->delete(route('internal-projects.destroy', $this->project))->assertForbidden();
        }

        $this->actingAs($this->responsible)->get(route('internal-projects.edit', $this->project))->assertOk()->assertSee('Nuevo CRM');
        $this->actingAs($this->responsible)->put(route('internal-projects.update', $this->project), $this->projectPayload([
            'name' => 'CRM renovado',
            'code' => 'CRM-01',
            'status' => 'en_curso',
            'responsible_user_id' => $this->responsible->id,
            'member_ids' => [$this->viewer->id],
            'audience_type' => 'all',
        ]))->assertRedirect(route('internal-projects.show', $this->project));

        $project = $this->project->fresh();
        $this->assertSame('CRM renovado', $project->name);
        $this->assertSame('all', $project->audience_type);
        $this->assertSame(0, $project->audienceUsers()->count());
        $this->assertEqualsCanonicalizing([$this->responsible->id, $this->viewer->id], $project->members()->pluck('users.id')->all());

        $this->actingAs($this->admin)->delete(route('internal-projects.destroy', $this->project))
            ->assertRedirect(route('internal-projects.index'));
        $this->assertSoftDeleted($this->project);
    }

    // ---- S5 visibility ------------------------------------------------------------

    public function test_outsider_gets_404_on_every_project_route(): void
    {
        $task = $this->task();
        $this->actingAs($this->outsider);

        $this->get(route('internal-projects.show', $this->project))->assertNotFound();
        $this->get(route('internal-projects.edit', $this->project))->assertNotFound();
        $this->put(route('internal-projects.update', $this->project), [])->assertNotFound();
        $this->delete(route('internal-projects.destroy', $this->project))->assertNotFound();
        $this->post(route('internal-projects.phases.store', $this->project), ['name' => 'X'])->assertNotFound();
        $this->post(route('internal-projects.tasks.store', $this->project), [])->assertNotFound();
        $this->patch(route('internal-projects.tasks.status', [$this->project, $task]), ['status' => 'terminada'])->assertNotFound();
        $this->get('/internal-projects/999999')->assertNotFound();
    }

    public function test_audience_viewer_sees_project_read_only_and_gets_403_on_writes(): void
    {
        $task = $this->task(['title' => 'Diseñar base de datos']);
        $this->actingAs($this->viewer);

        $this->get(route('internal-projects.show', $this->project))
            ->assertOk()
            ->assertSee('Diseñar base de datos')
            ->assertSee('id="task-'.$task->id.'"', false)
            ->assertSee(route('internal-projects.timeline', $this->project), false)
            ->assertDontSee('data-testid="btn-edit-project"', false)
            ->assertDontSee('data-testid="btn-add-phase"', false)
            ->assertDontSee('data-testid="btn-add-task"', false)
            ->assertDontSee('data-testid="task-status-form"', false);

        $this->post(route('internal-projects.phases.store', $this->project), ['name' => 'Pruebas'])->assertForbidden();
        $this->put(route('internal-projects.phases.update', [$this->project, $this->phase]), ['name' => 'X'])->assertForbidden();
        $this->delete(route('internal-projects.phases.destroy', [$this->project, $this->phase]))->assertForbidden();
        $this->post(route('internal-projects.tasks.store', $this->project), $this->taskPayload())->assertForbidden();
        $this->put(route('internal-projects.tasks.update', [$this->project, $task]), $this->taskPayload())->assertForbidden();
        $this->patch(route('internal-projects.tasks.status', [$this->project, $task]), ['status' => 'terminada'])->assertForbidden();
        $this->delete(route('internal-projects.tasks.destroy', [$this->project, $task]))->assertForbidden();
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    // ---- team work -------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'internal_project_phase_id' => $this->phase->id,
            'title' => 'Construir API',
            'responsible_user_id' => $this->member->id,
            'starts_on' => '2026-11-02',
            'due_on' => '2026-11-20',
            'weight' => 2,
            'is_milestone' => '1',
            'status' => 'pendiente',
        ], $overrides);
    }

    public function test_member_manages_phases_tasks_and_statuses(): void
    {
        $this->actingAs($this->member);
        $show = route('internal-projects.show', $this->project);

        $this->get($show)->assertOk()
            ->assertSee('data-testid="btn-add-phase"', false)
            ->assertDontSee('data-testid="btn-edit-project"', false);

        $this->post(route('internal-projects.phases.store', $this->project), ['name' => 'Pruebas', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-15'])
            ->assertRedirect();
        $testing = $this->project->phases()->where('name', 'Pruebas')->firstOrFail();
        $this->assertGreaterThan($this->phase->sort, $testing->sort);

        $this->put(route('internal-projects.phases.update', [$this->project, $testing]), ['name' => 'QA'])->assertRedirect();
        $this->assertSame('QA', $testing->fresh()->name);

        $this->post(route('internal-projects.tasks.store', $this->project), $this->taskPayload())->assertRedirect();
        $task = InternalProjectTask::query()->where('title', 'Construir API')->firstOrFail();
        $this->assertSame(2, $task->weight);
        $this->assertTrue($task->is_milestone);
        $this->assertSame($this->member->id, $task->created_by);

        $this->task(['weight' => 2]);

        $this->put(route('internal-projects.tasks.update', [$this->project, $task]), $this->taskPayload(['title' => 'Construir API REST', 'is_milestone' => '0']))
            ->assertRedirect();
        $this->assertSame('Construir API REST', $task->fresh()->title);
        $this->assertFalse($task->fresh()->is_milestone);

        $this->patch(route('internal-projects.tasks.status', [$this->project, $task]), ['status' => 'terminada'])
            ->assertRedirect($show.'#task-'.$task->id);
        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertDatabaseHas('internal_project_task_status_changes', [
            'internal_project_task_id' => $task->id,
            'from_status' => 'pendiente',
            'to_status' => 'terminada',
            'user_id' => $this->member->id,
        ]);
        $this->get($show)->assertSee('data-testid="project-percent">50 %', false);

        $this->delete(route('internal-projects.tasks.destroy', [$this->project, $task]))->assertRedirect();
        $this->assertSoftDeleted($task);

        $this->delete(route('internal-projects.phases.destroy', [$this->project, $testing]))->assertRedirect();
        $this->assertDatabaseMissing('internal_project_phases', ['id' => $testing->id]);
    }

    public function test_task_validation_and_domain_errors_return_to_the_form(): void
    {
        $this->actingAs($this->member)
            ->post(route('internal-projects.tasks.store', $this->project), $this->taskPayload(['title' => '', 'due_on' => '2026-10-01', 'weight' => 0]))
            ->assertSessionHasErrors(['title', 'due_on', 'weight']);

        $phaseElsewhere = InternalProjectPhase::factory()->create();
        $this->actingAs($this->member)
            ->post(route('internal-projects.tasks.store', $this->project), $this->taskPayload(['internal_project_phase_id' => $phaseElsewhere->id]))
            ->assertSessionHasErrors(['internal_project_phase_id']);

        $this->actingAs($this->member)
            ->patch(route('internal-projects.tasks.status', [$this->project, $this->task()]), ['status' => 'inventado'])
            ->assertSessionHasErrors(['status']);

        $this->assertSame(1, InternalProjectTask::query()->count());
    }

    public function test_phase_with_tasks_cannot_be_deleted(): void
    {
        $this->task();

        $this->actingAs($this->member)->delete(route('internal-projects.phases.destroy', [$this->project, $this->phase]))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('internal_project_phases', ['id' => $this->phase->id]);
    }

    public function test_task_or_phase_from_another_project_is_404(): void
    {
        $other = InternalProject::factory()->create(['responsible_user_id' => $this->member->id, 'created_by' => $this->member->id]);
        $otherPhase = InternalProjectPhase::factory()->for($other, 'project')->create();
        $otherTask = InternalProjectTask::factory()->for($otherPhase, 'phase')->create();
        $this->actingAs($this->member);

        $this->patch(route('internal-projects.tasks.status', [$this->project, $otherTask]), ['status' => 'terminada'])->assertNotFound();
        $this->put(route('internal-projects.tasks.update', [$this->project, $otherTask]), $this->taskPayload())->assertNotFound();
        $this->delete(route('internal-projects.tasks.destroy', [$this->project, $otherTask]))->assertNotFound();
        $this->put(route('internal-projects.phases.update', [$this->project, $otherPhase]), ['name' => 'X'])->assertNotFound();
        $this->delete(route('internal-projects.phases.destroy', [$this->project, $otherPhase]))->assertNotFound();
        $this->assertSame(TaskStatus::Pending, $otherTask->fresh()->status);
    }

    public function test_show_filters_tasks_by_status_phase_and_responsible(): void
    {
        $this->task(['title' => 'Tarea terminada', 'status' => TaskStatus::Done, 'responsible_user_id' => $this->member->id]);
        $this->task(['title' => 'Tarea pendiente']);

        $this->actingAs($this->viewer)->get(route('internal-projects.show', [$this->project, 'status' => 'terminada']))
            ->assertOk()->assertSee('Tarea terminada')->assertDontSee('Tarea pendiente');
        $this->actingAs($this->viewer)->get(route('internal-projects.show', [$this->project, 'responsible' => $this->member->id]))
            ->assertOk()->assertSee('Tarea terminada')->assertDontSee('Tarea pendiente');
    }

    // ---- S6 sidebar -----------------------------------------------------------------

    public function test_sidebar_has_internal_projects_link_for_every_active_user(): void
    {
        $this->actingAs($this->outsider)->get(route('internal-projects.index'))
            ->assertOk()
            ->assertSee('data-testid="sidebar-internal-projects"', false)
            ->assertSee('Proyectos internos');
    }
}
