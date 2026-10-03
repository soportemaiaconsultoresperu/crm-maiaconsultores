<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentProject;
use App\Models\User;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3 — content project administration UI.
 *
 * The request path is where T2's authorization actually bites, so every rule
 * below is exercised with a NON-admin actor: the seeded `admin` role
 * short-circuits every Gate check through `AuthServiceProvider`'s `Gate::before`
 * bypass, so an assertion made as admin would prove nothing about the boundary.
 *
 * Two invariants the surface must never break:
 * - A project the actor may not reach answers 404, never 403, so a foreign
 *   project's existence is not disclosed.
 * - `content_projects.view_all` widens READING only; it grants no mutation the
 *   actor was not given.
 */
class ContentProjectHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);
    }

    /**
     * The operational role: holds `content_projects.view` but no membership.
     */
    private function reader(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('supervisor');

        return $user;
    }

    /**
     * A reader with the requested mutation permissions granted on top.
     *
     * @param  list<string>  $permissions
     */
    private function manager(array $permissions): User
    {
        $user = $this->reader();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /**
     * @return list<int>
     */
    private function memberIds(ContentProject $project): array
    {
        return $project->members()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_a_user_without_permission_is_refused(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);

        $this->assertFalse($stranger->hasRole('admin'));

        $this->actingAs($stranger)->get(route('content.projects.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('content.projects.create'))->assertForbidden();
        $this->actingAs($stranger)
            ->post(route('content.projects.store'), ['name' => 'Intruso', 'slug' => 'intruso'])
            ->assertForbidden();
    }

    public function test_a_member_reaches_the_list_and_their_project(): void
    {
        $member = $this->reader();
        $project = ContentProject::factory()->create(['name' => 'Maia Consultores', 'slug' => 'maia-consultores']);
        $project->members()->attach($member->id);

        $this->actingAs($member)
            ->get(route('content.projects.index'))
            ->assertOk()
            ->assertSee('Maia Consultores');

        $this->actingAs($member)
            ->get(route('content.projects.show', $project))
            ->assertOk()
            ->assertSee('Maia Consultores')
            ->assertSee('maia-consultores');
    }

    public function test_a_non_member_gets_404_not_403_on_a_foreign_project(): void
    {
        // Two actors: one with only the module read permission, one holding
        // every mutation permission. Neither is a member, so neither may learn
        // that the project exists.
        $readOnly = $this->reader();
        $powerful = $this->manager([
            'content_projects.update',
            'content_projects.archive',
            'content_projects.assign_members',
        ]);

        $foreign = ContentProject::factory()->create();

        foreach ([$readOnly, $powerful] as $actor) {
            $this->actingAs($actor)->get(route('content.projects.show', $foreign))->assertNotFound();
            $this->actingAs($actor)->get(route('content.projects.edit', $foreign))->assertNotFound();
            $this->actingAs($actor)
                ->put(route('content.projects.update', $foreign), ['name' => 'Robado', 'slug' => 'robado'])
                ->assertNotFound();
            $this->actingAs($actor)->post(route('content.projects.archive', $foreign))->assertNotFound();
            $this->actingAs($actor)
                ->post(route('content.projects.members.sync', $foreign), ['members' => []])
                ->assertNotFound();
        }

        // Nothing changed and nothing leaked.
        $this->assertSame($foreign->name, $foreign->fresh()->name);
        $this->assertNull($foreign->fresh()->archived_at);
    }

    public function test_unreachable_projects_return_404_before_validation_in_json_and_browser_requests(): void
    {
        $actor = $this->manager([
            'content_projects.update',
            'content_projects.archive',
            'content_projects.assign_members',
        ]);
        $foreign = ContentProject::factory()->create();
        $original = $foreign->fresh()->getAttributes();

        $this->actingAs($actor);

        // Both existing foreign records and missing ids must be indistinguishable,
        // including payloads that would otherwise produce 422 or a redirect.
        foreach ([$foreign->id, $foreign->id + 1000] as $id) {
            foreach ([['Accept' => 'application/json'], []] as $headers) {
                foreach ([
                    ['GET', 'show', []],
                    ['GET', 'edit', []],
                    ['PUT', 'update', ['name' => '', 'slug' => 'INVALID SLUG']],
                    ['POST', 'members.sync', ['members' => 'not-an-array']],
                    ['POST', 'archive', []],
                    ['POST', 'reactivate', []],
                ] as [$method, $action, $payload]) {
                    $this->call($method, route('content.projects.'.$action, $id), $payload, [], [],
                        $this->transformHeadersToServerVars($headers))
                        ->assertNotFound();
                }
            }
        }

        $this->assertSame($original, $foreign->fresh()->getAttributes());
        $this->assertSame([], $this->memberIds($foreign));
    }

    public function test_authorized_create_and_edit_pages_render(): void
    {
        $manager = $this->manager(['content_projects.create', 'content_projects.update']);
        $project = ContentProject::factory()->create();
        $project->members()->attach($manager->id);

        $this->actingAs($manager)->get(route('content.projects.create'))
            ->assertOk()->assertViewIs('content.projects.create');
        $this->actingAs($manager)->get(route('content.projects.edit', $project))
            ->assertOk()->assertViewIs('content.projects.edit');
    }

    public function test_reachable_projects_without_mutation_abilities_return_403_and_do_not_change(): void
    {
        $member = $this->reader();
        $project = ContentProject::factory()->create();
        $project->members()->attach($member->id);
        $original = $project->fresh()->getAttributes();

        $this->actingAs($member)->putJson(route('content.projects.update', $project), [
            'name' => 'Forbidden change', 'slug' => 'forbidden-change',
        ])->assertForbidden();
        $this->postJson(route('content.projects.members.sync', $project), ['members' => []])
            ->assertForbidden();
        $this->postJson(route('content.projects.archive', $project))->assertForbidden();

        $this->assertSame($original, $project->fresh()->getAttributes());
        $this->assertSame([$member->id], $this->memberIds($project));
    }

    public function test_update_permission_does_not_allow_reactivation(): void
    {
        $editor = $this->manager(['content_projects.update']);
        $project = ContentProject::factory()->archived()->create();
        $project->members()->attach($editor->id);
        $original = $project->fresh()->getAttributes();

        $this->actingAs($editor)->get(route('content.projects.show', $project))
            ->assertOk()
            ->assertDontSee('data-testid="content-project-reactivate-form"', false)
            ->assertSee('data-testid="btn-edit-content-project"', false);

        $this->postJson(route('content.projects.reactivate', $project))
            ->assertForbidden();

        $this->assertSame($original, $project->fresh()->getAttributes());
        $this->assertNotNull($project->fresh()->archived_at);
        $this->assertFalse($project->fresh()->is_active);
    }

    public function test_an_archived_project_stays_readable_but_leaves_the_default_list(): void
    {
        $member = $this->reader();
        $active = ContentProject::factory()->create(['name' => 'Proyecto Activo']);
        $archived = ContentProject::factory()->archived()->create(['name' => 'Proyecto Archivado']);

        $active->members()->attach($member->id);
        $archived->members()->attach($member->id);

        $this->actingAs($member)
            ->get(route('content.projects.index'))
            ->assertOk()
            ->assertSee('Proyecto Activo')
            ->assertDontSee('Proyecto Archivado');

        // Reachability is unchanged by archiving.
        $this->actingAs($member)
            ->get(route('content.projects.show', $archived))
            ->assertOk()
            ->assertSee('Proyecto Archivado');

        // Asking for them brings them back into the list.
        $this->actingAs($member)
            ->get(route('content.projects.index', ['include_archived' => 1]))
            ->assertOk()
            ->assertSee('Proyecto Archivado');
    }

    public function test_creating_a_project_validates_required_fields_and_slug_uniqueness(): void
    {
        $manager = $this->manager(['content_projects.create']);

        $this->actingAs($manager)
            ->post(route('content.projects.store'), [])
            ->assertSessionHasErrors(['name', 'slug']);

        ContentProject::factory()->create(['slug' => 'maia-consultores']);

        $this->actingAs($manager)
            ->post(route('content.projects.store'), ['name' => 'Otro proyecto', 'slug' => 'maia-consultores'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($manager)
            ->post(route('content.projects.store'), ['name' => 'Maia Plagas', 'slug' => 'maia-plagas'])
            ->assertRedirect();

        $this->assertDatabaseHas('content_projects', [
            'name' => 'Maia Plagas',
            'slug' => 'maia-plagas',
        ]);
    }

    public function test_updating_a_project_ignores_its_own_slug_but_not_another_projects(): void
    {
        $manager = $this->manager(['content_projects.update']);
        $project = ContentProject::factory()->create(['slug' => 'maia-consultores']);
        $project->members()->attach($manager->id);
        $other = ContentProject::factory()->create(['slug' => 'maia-residuos']);

        // Keeping its own slug is not a uniqueness violation.
        $this->actingAs($manager)
            ->put(route('content.projects.update', $project), [
                'name' => 'Maia Consultores Renombrado',
                'slug' => 'maia-consultores',
            ])
            ->assertRedirect();

        $this->assertSame('Maia Consultores Renombrado', $project->fresh()->name);

        // Taking another project's slug is.
        $this->actingAs($manager)
            ->put(route('content.projects.update', $project), [
                'name' => 'Maia Consultores',
                'slug' => 'maia-residuos',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame('maia-consultores', $project->fresh()->slug);
        $this->assertSame('maia-residuos', $other->fresh()->slug);
    }

    public function test_archiving_and_reactivating_work(): void
    {
        $manager = $this->manager(['content_projects.archive']);
        $project = ContentProject::factory()->create(['is_active' => true]);
        $project->members()->attach($manager->id);

        $this->actingAs($manager)
            ->post(route('content.projects.archive', $project))
            ->assertRedirect();

        $this->assertNotNull($project->fresh()->archived_at);
        $this->assertFalse($project->fresh()->is_active);
        // Archiving retires the project; it must NOT send it to the trash.
        $this->assertNull($project->fresh()->deleted_at);

        $this->actingAs($manager)->get(route('content.projects.show', $project))
            ->assertOk()
            ->assertSee('data-testid="content-project-reactivate-form"', false)
            ->assertDontSee('data-testid="btn-edit-content-project"', false);

        $this->actingAs($manager)
            ->post(route('content.projects.reactivate', $project))
            ->assertRedirect();

        $this->assertNull($project->fresh()->archived_at);
        $this->assertTrue($project->fresh()->is_active);
    }

    public function test_member_sync_replaces_membership_as_intended(): void
    {
        $manager = $this->manager(['content_projects.assign_members']);
        $project = ContentProject::factory()->create();
        $project->members()->attach($manager->id);

        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);

        // Adding: the submitted list becomes the membership.
        $this->actingAs($manager)
            ->post(route('content.projects.members.sync', $project), [
                'members' => [$manager->id, $first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$manager->id, $first->id, $second->id],
            $this->memberIds($project),
        );

        // Replacing: an omitted member is removed.
        $this->actingAs($manager)
            ->post(route('content.projects.members.sync', $project), [
                'members' => [$manager->id, $first->id],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing([$manager->id, $first->id], $this->memberIds($project));
        $this->assertDatabaseMissing('content_project_user', [
            'content_project_id' => $project->id,
            'user_id' => $second->id,
        ]);

        // Clearing: an empty list revokes every membership.
        $this->actingAs($manager)
            ->post(route('content.projects.members.sync', $project), ['members' => []])
            ->assertRedirect();

        $this->assertSame([], $this->memberIds($project));
    }

    public function test_view_all_reads_across_projects_without_gaining_mutations(): void
    {
        // No role at all: the cross-project view must come from the PERMISSION.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('content_projects.view_all');

        $first = ContentProject::factory()->create(['name' => 'Proyecto Uno']);
        $second = ContentProject::factory()->create(['name' => 'Proyecto Dos']);

        $this->assertFalse($viewer->hasRole('admin'));

        $this->actingAs($viewer)
            ->get(route('content.projects.index'))
            ->assertOk()
            ->assertSee('Proyecto Uno')
            ->assertSee('Proyecto Dos');

        $this->actingAs($viewer)
            ->get(route('content.projects.show', $second))
            ->assertOk()
            ->assertSee('Proyecto Dos');

        // Reading across every project grants no mutation the actor lacks.
        $this->actingAs($viewer)->get(route('content.projects.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('content.projects.edit', $second))->assertForbidden();
        $this->actingAs($viewer)
            ->put(route('content.projects.update', $second), ['name' => 'Secuestrado', 'slug' => 'secuestrado'])
            ->assertForbidden();
        $this->actingAs($viewer)->post(route('content.projects.archive', $second))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('content.projects.members.sync', $second), ['members' => [$viewer->id]])
            ->assertForbidden();

        $this->assertSame('Proyecto Dos', $second->fresh()->name);
        $this->assertNull($second->fresh()->archived_at);
        $this->assertSame([], $this->memberIds($second));
    }
}
