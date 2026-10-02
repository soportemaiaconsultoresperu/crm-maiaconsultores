<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentProject;
use App\Models\Team;
use App\Models\User;
use App\Policies\Content\ContentProjectPolicy;
use App\Services\Content\ContentProjectScopeService;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\CoursePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * T2 — authorization core for the content scheduling module.
 *
 * Every rule below is exercised with a NON-admin actor on purpose: the seeded
 * `admin` role short-circuits every Gate check through the `Gate::before` bypass
 * in `App\Providers\AuthServiceProvider`, so an assertion made as admin would
 * prove nothing about the authorization boundary. Admin is used only to assert
 * what the seeder GRANTS, never what a policy DECIDES.
 */
class ContentProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);
    }

    /**
     * A user in the operational role: holds the module read permission, but no
     * membership in any project.
     */
    private function operationalUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');

        return $user;
    }

    private function scope(): ContentProjectScopeService
    {
        return app(ContentProjectScopeService::class);
    }

    /**
     * @return list<int>
     */
    private function visibleIds(User $user, bool $includeArchived = false): array
    {
        return $this->scope()
            ->visibleProjects($user, $includeArchived)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function test_laravel_policy_discovery_resolves_the_content_project_policy(): void
    {
        // The policy is NOT listed in AuthServiceProvider::$policies, so this
        // asserts the framework's name-based discovery really resolves it at
        // App\Policies\Content\ContentProjectPolicy. A future move of the file
        // turns this red instead of silently granting/denying everything.
        $this->assertInstanceOf(
            ContentProjectPolicy::class,
            Gate::getPolicyFor(ContentProject::class),
        );

        // Same resolution, exercised through the Gate rather than directly.
        $member = $this->operationalUser();
        $project = ContentProject::factory()->create();
        $project->members()->attach($member->id);

        $this->assertTrue(Gate::forUser($member)->allows('view', $project));
    }

    public function test_a_user_without_membership_resolves_to_zero_projects_and_no_access(): void
    {
        $user = $this->operationalUser();
        $project = ContentProject::factory()->create();

        $this->assertSame([], $this->visibleIds($user));
        $this->assertFalse($this->scope()->canAccess($user, $project));
        $this->assertFalse(Gate::forUser($user)->allows('view', $project));

        // Holding the module read permission opens the module (viewAny) but
        // grants no project: reach is resolved per record.
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', ContentProject::class));
    }

    public function test_an_assigned_user_sees_and_reaches_exactly_the_assigned_project(): void
    {
        $user = $this->operationalUser();
        $assigned = ContentProject::factory()->create();
        $other = ContentProject::factory()->create();

        $assigned->members()->attach($user->id);

        $this->assertSame([(int) $assigned->id], $this->visibleIds($user));
        $this->assertTrue($this->scope()->canAccess($user, $assigned));
        $this->assertFalse($this->scope()->canAccess($user, $other));
        $this->assertTrue(Gate::forUser($user)->allows('view', $assigned->fresh()));
        $this->assertFalse(Gate::forUser($user)->allows('view', $other));
    }

    public function test_the_cross_project_permission_reads_and_reaches_every_project(): void
    {
        // No role at all: the consolidated view must come from the PERMISSION,
        // never from a hardcoded role name.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(ContentProjectScopeService::VIEW_ALL);

        $first = ContentProject::factory()->create();
        $second = ContentProject::factory()->create();
        $archived = ContentProject::factory()->archived()->create();

        $this->assertFalse($viewer->hasRole('admin'));
        $this->assertTrue($this->scope()->hasCrossProjectView($viewer));
        $this->assertSame(
            collect([$first->id, $second->id])->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            $this->visibleIds($viewer),
        );
        $this->assertTrue($this->scope()->canAccess($viewer, $first));
        $this->assertTrue($this->scope()->canAccess($viewer, $second));
        // Archived stays reachable for the cross-project viewer too.
        $this->assertTrue($this->scope()->canAccess($viewer, $archived));
        $this->assertCount(3, $this->visibleIds($viewer, includeArchived: true));
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $second->fresh()));
        $this->assertTrue(Gate::forUser($viewer)->allows('viewAny', ContentProject::class));
    }

    public function test_the_policy_denies_by_default_for_a_user_without_any_content_permission(): void
    {
        $stranger = User::factory()->create();
        $project = ContentProject::factory()->create();

        $this->assertFalse($stranger->hasRole('admin'));

        $gate = Gate::forUser($stranger);

        $this->assertFalse($gate->allows('viewAny', ContentProject::class));
        $this->assertFalse($gate->allows('view', $project));
        $this->assertFalse($gate->allows('create', ContentProject::class));
        $this->assertFalse($gate->allows('update', $project));
        $this->assertFalse($gate->allows('archive', $project));
        $this->assertFalse($gate->allows('assignMembers', $project));

        // Deny by default also means "no membership" is not a silent grant.
        $this->assertSame([], $this->visibleIds($stranger));
        $this->assertFalse($this->scope()->canAccess($stranger, $project));
    }

    public function test_an_archived_project_stays_readable_but_leaves_the_default_visible_list(): void
    {
        $user = $this->operationalUser();
        $active = ContentProject::factory()->create();
        $archived = ContentProject::factory()->archived()->create();

        $active->members()->attach($user->id);
        $archived->members()->attach($user->id);

        $this->assertTrue($archived->isArchived());

        // Excluded from the default listing...
        $this->assertSame([(int) $active->id], $this->visibleIds($user));
        // ...but not from reachability: archiving retires the project, it does
        // not revoke the members' access to it.
        $this->assertTrue($this->scope()->canAccess($user, $archived));
        $this->assertTrue(Gate::forUser($user)->allows('view', $archived->fresh()));
        $this->assertCount(2, $this->visibleIds($user, includeArchived: true));
    }

    public function test_crm_team_and_owner_scopes_do_not_grant_content_project_access(): void
    {
        $supervisor = $this->operationalUser();
        $teammate = User::factory()->create();
        $teammate->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($teammate->id);

        $project = ContentProject::factory()->create();
        $project->members()->attach($teammate->id);

        // The supervisor leads the team, but leading a team is NOT content
        // project membership: they see nothing and reach nothing.
        $this->assertSame([], $this->visibleIds($supervisor));
        $this->assertFalse($this->scope()->canAccess($supervisor, $project));
        $this->assertFalse(Gate::forUser($supervisor)->allows('view', $project));

        // The teammate's access comes from the pivot, not from the team.
        $this->assertTrue($this->scope()->canAccess($teammate, $project));
    }

    public function test_the_seeded_vocabulary_is_complete_and_wired_to_the_policy(): void
    {
        foreach (ContentPermissionsSeeder::PERMISSIONS as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->where('guard_name', 'web')->exists(),
                "Permission [{$name}] must be seeded.",
            );
        }

        $admin = Role::findByName('admin', 'web');

        foreach (ContentPermissionsSeeder::PERMISSIONS as $name) {
            $this->assertTrue($admin->hasPermissionTo($name), "admin must hold [{$name}].");
        }

        $supervisor = Role::findByName('supervisor', 'web');
        $this->assertTrue($supervisor->hasPermissionTo('content_projects.view'));
        $this->assertFalse($supervisor->hasPermissionTo('content_projects.view_all'));

        // The abilities the policy exposes map onto the seeded names.
        $manager = User::factory()->create();
        $manager->assignRole('supervisor');
        $manager->givePermissionTo([
            'content_projects.create',
            'content_projects.update',
            'content_projects.archive',
            'content_projects.assign_members',
        ]);

        $project = ContentProject::factory()->create();
        $project->members()->attach($manager->id);

        $gate = Gate::forUser($manager);
        $this->assertTrue($gate->allows('create', ContentProject::class));
        $this->assertTrue($gate->allows('update', $project));
        $this->assertTrue($gate->allows('archive', $project));
        $this->assertTrue($gate->allows('assignMembers', $project));

        // A permission alone never widens a mutation to a foreign project.
        $foreign = ContentProject::factory()->create();
        $this->assertFalse($gate->allows('update', $foreign));
        $this->assertFalse($gate->allows('archive', $foreign));
        $this->assertFalse($gate->allows('assignMembers', $foreign));
    }

    public function test_the_permission_seeder_does_not_detach_other_modules_permissions(): void
    {
        // `RolesAndPermissionsSeeder` (and `CoursePermissionsSeeder`) use
        // `syncPermissions()`, which detaches anything missing from their own
        // lists. Re-running the full chain pins the contract that the content
        // seeder ADDS its vocabulary without revoking another module's rows.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CoursePermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        // Unrelated module permissions still exist as rows...
        $this->assertTrue(Permission::where('name', 'leads.view.any')->exists());
        $this->assertTrue(Permission::where('name', 'course-talks.view')->exists());

        // ...and still resolve for the roles that were granted them elsewhere.
        $admin = Role::findByName('admin', 'web');
        $supervisor = Role::findByName('supervisor', 'web');

        $this->assertTrue($supervisor->hasPermissionTo('leads.view.team'));
        $this->assertTrue($supervisor->hasPermissionTo('course-talks.view'));
        $this->assertTrue($admin->hasPermissionTo('leads.view.any'));
        $this->assertTrue($admin->hasPermissionTo('quotations.accept'));

        // The module vocabulary is added on top, not in place of them.
        foreach (ContentPermissionsSeeder::PERMISSIONS as $name) {
            $this->assertTrue($admin->hasPermissionTo($name), "admin must still hold [{$name}].");
        }

        $this->assertTrue($supervisor->hasPermissionTo('content_projects.view'));
        $this->assertFalse($supervisor->hasPermissionTo('content_projects.view_all'));

        // End to end: a seeded operational user resolves the new permission.
        $user = User::factory()->create();
        $user->assignRole('supervisor');
        $this->assertTrue($user->can('content_projects.view'));
    }
}
