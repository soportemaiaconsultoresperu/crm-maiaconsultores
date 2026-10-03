<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use App\Services\Content\ContentCatalogAdministrationService;
use App\Services\Content\ContentCatalogSelectionService;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContentCatalogHttpTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;
    private ContentPillar $pillar;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);
        $this->project = ContentProject::factory()->create();
        $this->pillar = ContentPillar::factory()->create(['content_project_id' => $this->project->id, 'name' => 'Capacitación']);
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->manager);
        $this->actingAs($this->manager);
    }

    private function payload(string $group): array
    {
        return ['name' => 'Nuevo curso', 'slug' => 'nuevo-curso', 'sort' => 10]
            + ($group === 'topics' ? ['content_pillar_id' => $this->pillar->id] : []);
    }

    public function test_member_with_update_can_manage_all_five_groups_and_keep_stable_slugs(): void
    {
        $this->get(route('content.projects.show', $this->project))->assertOk()
            ->assertSee(route('content.catalogs.index', $this->project), false);
        $this->get(route('content.catalogs.index', $this->project))->assertOk()
            ->assertSee('Capacitación')->assertSee('No hay opciones registradas');

        foreach (ContentCatalogAdministrationService::GROUPS as $group => $definition) {
            $this->post(route('content.catalogs.store', [$this->project, $group]), $this->payload($group))->assertRedirect();
            $model = $definition['model'];
            $option = $model::query()->where('slug', 'nuevo-curso')->sole();
            $parameters = [$this->project, $group, $option->id];
            $parent = $group === 'topics' ? ['content_pillar_id' => $this->pillar->id] : [];
            $this->put(route('content.catalogs.update', $parameters), ['name' => 'Nombre actualizado'] + $parent)->assertRedirect();
            $this->assertSame('nuevo-curso', $option->fresh()->slug);
            $this->assertSame('Nombre actualizado', $option->fresh()->name);
            $this->putJson(route('content.catalogs.update', $parameters), ['name' => 'Otro', 'slug' => 'otro'] + $parent)
                ->assertUnprocessable()->assertJsonValidationErrors('slug');
            $this->post(route('content.catalogs.reorder', $parameters), ['sort' => 77] + $parent)->assertRedirect();
            $this->assertSame(77, $option->fresh()->sort);
            $this->post(route('content.catalogs.state', $parameters), ['is_active' => false] + $parent)->assertRedirect();
            $this->assertFalse($option->fresh()->is_active);
            $this->assertFalse($model::query()->active()->whereKey($option->id)->exists());
            $this->get(route('content.catalogs.index', $this->project))->assertOk()->assertSee('Retirada');
            $this->postJson(route('content.catalogs.store', [$this->project, $group]), $this->payload($group))
                ->assertUnprocessable()->assertJsonValidationErrors('slug');
            $this->post(route('content.catalogs.state', $parameters), ['is_active' => true] + $parent)->assertRedirect();
            $this->assertTrue($option->fresh()->is_active);
        }
    }

    public function test_unreachable_project_is_404_for_screen_and_every_action_even_invalid_payloads(): void
    {
        $foreign = ContentProject::factory()->create();
        $this->get(route('content.catalogs.index', $foreign))->assertNotFound();
        $this->postJson(route('content.catalogs.store', [$foreign, 'pillars']), [])->assertNotFound();
        foreach (['state', 'reorder', 'update'] as $action) {
            $url = route('content.catalogs.'.$action, [$foreign, 'pillars', $this->pillar->id]);
            ($action === 'update' ? $this->putJson($url, []) : $this->postJson($url, []))->assertNotFound();
        }
    }

    public function test_member_without_update_is_refused_before_validation(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);
        $this->actingAs($reader);
        $this->get(route('content.catalogs.index', $this->project))->assertForbidden();
        $this->get(route('content.projects.show', $this->project))->assertOk()->assertDontSee('Administrar catálogos');
        $this->postJson(route('content.catalogs.store', [$this->project, 'pillars']), [])->assertForbidden();
        foreach (['state', 'reorder', 'update'] as $action) {
            $url = route('content.catalogs.'.$action, [$this->project, 'pillars', $this->pillar->id]);
            ($action === 'update' ? $this->putJson($url, []) : $this->postJson($url, []))->assertForbidden();
        }
        $this->assertTrue($this->pillar->fresh()->is_active);
    }

    public function test_foreign_creation_targets_are_rejected_without_writing(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignPillar = ContentPillar::factory()->create(['content_project_id' => $foreign->id]);
        $this->postJson(route('content.catalogs.store', [$this->project, 'topics']),
            array_replace($this->payload('topics'), ['content_pillar_id' => $foreignPillar->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('content_pillar_id');
        foreach (['pillars', 'objectives', 'formats', 'channels'] as $group) {
            $this->postJson(route('content.catalogs.store', [$this->project, $group]),
                $this->payload($group) + ['content_project_id' => $foreign->id])
                ->assertUnprocessable()->assertJsonValidationErrors('content_project_id');
            $model = ContentCatalogAdministrationService::GROUPS[$group]['model'];
            $this->assertFalse($model::query()->where('slug', 'nuevo-curso')->exists());
        }
        $this->assertSame(0, ContentTopic::query()->count());
    }

    public function test_foreign_options_cannot_be_renamed_retired_or_reordered(): void
    {
        $foreign = ContentProject::factory()->create();
        foreach (['pillars', 'objectives', 'formats', 'channels'] as $group) {
            $model = ContentCatalogAdministrationService::GROUPS[$group]['model'];
            $option = $model::factory()->create(['content_project_id' => $foreign->id]);
            $parameters = [$this->project, $group, $option->id];
            $this->putJson(route('content.catalogs.update', $parameters), ['name' => 'Robado'])->assertUnprocessable();
            $this->postJson(route('content.catalogs.state', $parameters), ['is_active' => false])->assertUnprocessable();
            $this->postJson(route('content.catalogs.reorder', $parameters), ['sort' => 999])->assertUnprocessable();
            $this->assertTrue($option->fresh()->is_active);
            $this->assertNotSame('Robado', $option->fresh()->name);
            $this->assertNotSame(999, $option->fresh()->sort);
        }
    }

    public function test_topic_mutations_require_its_original_project_and_pillar(): void
    {
        $otherPillar = ContentPillar::factory()->create(['content_project_id' => $this->project->id]);
        $topic = ContentTopic::factory()->create(['content_pillar_id' => $otherPillar->id]);
        $parameters = [$this->project, 'topics', $topic->id];
        $parent = ['content_pillar_id' => $this->pillar->id];
        $this->putJson(route('content.catalogs.update', $parameters), ['name' => 'Robado'] + $parent)
            ->assertUnprocessable()->assertJsonValidationErrors('content_topic_id');
        $this->postJson(route('content.catalogs.state', $parameters), ['is_active' => false] + $parent)->assertUnprocessable();
        $this->postJson(route('content.catalogs.reorder', $parameters), ['sort' => 999] + $parent)->assertUnprocessable();
        $this->assertSame($otherPillar->id, $topic->fresh()->content_pillar_id);
        $this->assertTrue($topic->fresh()->is_active);
    }

    public function test_retired_topic_stays_readable_but_guard_rejects_new_selection_and_screen_orders_options(): void
    {
        $first = ContentTopic::factory()->create(['content_pillar_id' => $this->pillar->id, 'name' => 'Primero', 'sort' => 1]);
        $last = ContentTopic::factory()->create(['content_pillar_id' => $this->pillar->id, 'name' => 'Último', 'sort' => 20]);
        $this->post(route('content.catalogs.reorder', [$this->project, 'topics', $last->id]),
            ['content_pillar_id' => $this->pillar->id, 'sort' => 0])->assertRedirect();
        $this->get(route('content.catalogs.index', $this->project))->assertOk()->assertSeeInOrder(['Último', 'Primero']);
        $this->post(route('content.catalogs.state', [$this->project, 'topics', $first->id]),
            ['content_pillar_id' => $this->pillar->id, 'is_active' => false])->assertRedirect();
        $guard = app(ContentCatalogSelectionService::class);
        $guard->assertValidSelection($this->project, $this->pillar->id, $first->id, requireActive: false);
        $this->expectException(ValidationException::class);
        $guard->assertValidSelection($this->project, $this->pillar->id, $first->id);
    }
}
