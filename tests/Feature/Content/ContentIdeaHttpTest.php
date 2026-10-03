<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T6d — the ideas section HTTP surface.
 *
 * Covers registering an idea without a date, keeping it out of the content
 * grid, project isolation, the read/mutate authorization split, editing an idea
 * without turning it into a content, and the additive links that make the ideas
 * section and the grid reference each other.
 */
class ContentIdeaHttpTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);
        $this->project = ContentProject::factory()->create();
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->manager);
        $this->actingAs($this->manager);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Idea de lanzamiento',
            'body' => 'Texto de la propuesta.',
        ], $overrides);
    }

    public function test_idea_saves_without_a_planned_date_and_is_listed_only_among_ideas(): void
    {
        $this->post(route('content.projects.ideas.store', $this->project), $this->payload())
            ->assertRedirect();

        $idea = ContentItem::query()->where('title', 'Idea de lanzamiento')->sole();

        $this->assertTrue($idea->isIdea());
        $this->assertFalse($idea->isContent());
        $this->assertNull($idea->planned_at);

        $this->get(route('content.projects.ideas.index', $this->project))->assertOk()
            ->assertSee('Idea de lanzamiento')
            ->assertSee('Nueva idea');

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertDontSee('Idea de lanzamiento');
    }

    public function test_member_with_update_reaches_the_idea_section_screens(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Idea visible']);

        $this->get(route('content.projects.ideas.index', $this->project))->assertOk()
            ->assertSee('Idea visible');

        $this->get(route('content.projects.ideas.create', $this->project))->assertOk()
            ->assertSee('Nueva idea');

        $this->get(route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $idea->id]))
            ->assertOk()
            ->assertSee('Idea visible')
            ->assertSee('Convertir en contenido')
            ->assertSee('Horario en Perú');

        $this->get(route('content.projects.ideas.edit', ['contentProject' => $this->project, 'contentIdea' => $idea->id]))
            ->assertOk()
            ->assertSee('Editar idea');
    }

    public function test_project_detail_grid_and_ideas_reference_each_other(): void
    {
        $ideasUrl = route('content.projects.ideas.index', $this->project);
        $gridUrl = route('content.projects.contents.index', $this->project);

        $this->get(route('content.projects.show', $this->project))->assertOk()
            ->assertSee($ideasUrl, false);

        $this->get($gridUrl)->assertOk()
            ->assertSee($ideasUrl, false);

        $this->get($ideasUrl)->assertOk()
            ->assertSee($gridUrl, false);
    }

    public function test_non_member_gets_404_on_the_idea_routes(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignIdea = ContentItem::factory()->for($foreign, 'project')->idea()->create();

        $this->get(route('content.projects.ideas.index', $foreign))->assertNotFound();
        $this->get(route('content.projects.ideas.create', $foreign))->assertNotFound();
        $this->postJson(route('content.projects.ideas.store', $foreign), [])->assertNotFound();
        $this->get(route('content.projects.ideas.show', ['contentProject' => $foreign, 'contentIdea' => $foreignIdea->id]))->assertNotFound();
        $this->get(route('content.projects.ideas.edit', ['contentProject' => $foreign, 'contentIdea' => $foreignIdea->id]))->assertNotFound();
        $this->putJson(route('content.projects.ideas.update', ['contentProject' => $foreign, 'contentIdea' => $foreignIdea->id]), [])->assertNotFound();
        $this->postJson(route('content.projects.ideas.convert', ['contentProject' => $foreign, 'contentIdea' => $foreignIdea->id]), [])->assertNotFound();
    }

    public function test_a_foreign_or_converted_child_id_is_404_inside_the_addressed_project(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignIdea = ContentItem::factory()->for($foreign, 'project')->idea()->create();
        $converted = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Ya es contenido']);

        $show = route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $foreignIdea->id]);
        $this->get($show)->assertNotFound();

        $convertedShow = route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $converted->id]);
        $this->get($convertedShow)->assertNotFound();
    }

    public function test_actor_without_update_is_refused_and_mutates_nothing(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Original']);
        $this->actingAs($reader);

        // Reading the ideas section is allowed; the form and every mutation are not.
        $this->get(route('content.projects.ideas.index', $this->project))->assertOk();
        $this->get(route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $idea->id]))->assertOk();

        $this->get(route('content.projects.ideas.create', $this->project))->assertForbidden();
        $this->postJson(route('content.projects.ideas.store', $this->project), $this->payload())->assertForbidden();
        $this->get(route('content.projects.ideas.edit', ['contentProject' => $this->project, 'contentIdea' => $idea->id]))->assertForbidden();
        $this->putJson(route('content.projects.ideas.update', ['contentProject' => $this->project, 'contentIdea' => $idea->id]), $this->payload(['title' => 'Robado']))
            ->assertForbidden();
        $this->postJson(route('content.projects.ideas.convert', ['contentProject' => $this->project, 'contentIdea' => $idea->id]), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
        ])->assertForbidden();

        $this->assertSame(1, ContentItem::query()->count());
        $fresh = $idea->fresh();
        $this->assertSame('Original', $fresh->title);
        $this->assertTrue($fresh->isIdea());
        $this->assertNull($fresh->planned_at);
    }

    public function test_member_can_edit_an_idea_without_turning_it_into_a_content(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Antes']);

        $this->put(route('content.projects.ideas.update', ['contentProject' => $this->project, 'contentIdea' => $idea->id]), $this->payload([
            'title' => 'Después',
            'notes' => 'Nota agregada',
        ]))->assertRedirect(route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $idea->id]));

        $fresh = $idea->fresh();
        $this->assertSame('Después', $fresh->title);
        $this->assertSame('Nota agregada', $fresh->notes);
        $this->assertTrue($fresh->isIdea());
        $this->assertNull($fresh->planned_at);
    }
}
