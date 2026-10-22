<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentItemKind;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 (content-color-fields-export) — optional standard fields can be hidden
 * per project.
 *
 * An administrator (`configure`) chooses which optional standard fields the
 * project uses. A hidden field disappears from the forms, the grid columns and
 * filters and the detail pages; its stored data is kept untouched and shows up
 * again when the field is re-enabled; server validation ignores it. Fecha,
 * hora, estado, título y copy are mandatory and can never be hidden.
 */
class ContentHiddenStandardFieldsTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        $this->project = ContentProject::factory()->create();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->admin);
        $this->actingAs($this->admin);
    }

    /**
     * Every optional field marked as used except the given ones.
     *
     * @param  list<string>  $hidden
     * @return array<string, string>
     */
    private function settings(array $hidden): array
    {
        $standard = [];
        foreach (array_keys(ContentProject::HIDEABLE_STANDARD_FIELDS) as $key) {
            $standard[$key] = in_array($key, $hidden, true) ? '0' : '1';
        }

        return ['standard' => $standard];
    }

    /**
     * @param  list<string>  $hidden
     */
    private function hide(array $hidden): void
    {
        $this->project->forceFill(['hidden_standard_fields' => $hidden])->save();
        $this->project->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Contenido',
            'body' => 'Texto de la publicación.',
        ], $overrides);
    }

    /**
     * A content holding a value in every optional standard field.
     *
     * @return array{0: ContentItem, 1: ContentPillar, 2: ContentTopic, 3: ContentObjective, 4: ContentFormat, 5: ContentChannel}
     */
    private function fullContent(ContentItemKind $kind = ContentItemKind::Content): array
    {
        $pillar = ContentPillar::factory()->create(['content_project_id' => $this->project->id, 'name' => 'Pilar guardado']);
        $topic = ContentTopic::factory()->create(['content_pillar_id' => $pillar->id, 'name' => 'Tema guardado']);
        $objective = ContentObjective::factory()->create(['content_project_id' => $this->project->id, 'name' => 'Objetivo guardado']);
        $format = ContentFormat::factory()->create(['content_project_id' => $this->project->id, 'name' => 'Formato guardado']);
        $channel = ContentChannel::factory()->create(['content_project_id' => $this->project->id, 'name' => 'Canal guardado']);

        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'kind' => $kind,
            'planned_at' => $kind === ContentItemKind::Idea ? null : now()->addMonth(),
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'title' => 'Contenido completo',
            'hashtags' => '#guardado',
            'notes' => 'Nota guardada',
        ]);
        $item->objectives()->sync([$objective->id]);
        $item->formats()->sync([$format->id]);
        $item->channels()->sync([$channel->id]);

        return [$item, $pillar, $topic, $objective, $format, $channel];
    }

    public function test_an_admin_saves_the_standard_field_settings_from_the_fields_page(): void
    {
        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('Campos estándar')
            ->assertSee('data-testid="content-standard-fields-form"', false)
            ->assertSee('Obligatorio');

        $this->put(route('content.fields.standard.update', $this->project), $this->settings(['hashtags', 'notes']))
            ->assertRedirect(route('content.fields.index', $this->project))
            ->assertSessionHasNoErrors();

        $project = $this->project->fresh();
        $this->assertSame(['hashtags', 'notes'], $project->hidden_standard_fields);
        $this->assertTrue($project->isStandardFieldHidden('notes'));
        $this->assertFalse($project->isStandardFieldHidden('pillar'));
        $this->assertNotContains('hashtags', $project->visibleStandardFields());
        $this->assertContains('pillar', $project->visibleStandardFields());

        // Showing every field again stores an empty list.
        $this->put(route('content.fields.standard.update', $this->project), $this->settings([]))
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->project->fresh()->hidden_standard_fields);
    }

    public function test_hiding_the_pillar_also_hides_the_topic(): void
    {
        $this->put(route('content.fields.standard.update', $this->project), $this->settings(['pillar']))
            ->assertSessionHasNoErrors();

        $project = $this->project->fresh();
        $this->assertTrue($project->isStandardFieldHidden('pillar'));
        $this->assertTrue($project->isStandardFieldHidden('topic'));
        $this->assertNotContains('topic', $project->visibleStandardFields());
    }

    public function test_mandatory_fields_cannot_be_hidden(): void
    {
        $settings = $this->settings([]);
        $settings['standard']['title'] = '0';

        $this->from(route('content.fields.index', $this->project))
            ->put(route('content.fields.standard.update', $this->project), $settings)
            ->assertSessionHasErrors('standard');
        $this->assertNull($this->project->fresh()->hidden_standard_fields);

        // Even a value forced into the column never hides a mandatory field.
        $this->hide(['title', 'planned_date', 'status', 'body', 'planned_time']);
        foreach (['title', 'planned_date', 'planned_time', 'status', 'body'] as $key) {
            $this->assertFalse($this->project->isStandardFieldHidden($key));
        }
        $this->get(route('content.projects.contents.create', $this->project))->assertOk()
            ->assertSee('name="title"', false)
            ->assertSee('name="body"', false)
            ->assertSee('name="planned_date"', false);
    }

    public function test_a_member_and_an_archived_project_cannot_change_the_settings(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole('vendedor');
        $this->project->members()->attach($member);

        $this->actingAs($member)
            ->put(route('content.fields.standard.update', $this->project), $this->settings(['notes']))
            ->assertForbidden();
        $this->assertNull($this->project->fresh()->hidden_standard_fields);

        $this->project->archive();
        $this->actingAs($this->admin)
            ->put(route('content.fields.standard.update', $this->project), $this->settings(['notes']))
            ->assertForbidden();
        $this->assertNull($this->project->fresh()->hidden_standard_fields);
    }

    public function test_hidden_fields_disappear_from_the_content_and_idea_forms(): void
    {
        $this->hide(['pillar', 'objectives', 'channels', 'hashtags', 'notes']);
        [$item] = $this->fullContent();

        foreach ([
            route('content.projects.contents.create', $this->project),
            route('content.projects.contents.edit', [$this->project, $item]),
            route('content.projects.ideas.create', $this->project),
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('name="content_pillar_id"', false)
                ->assertDontSee('name="content_topic_id"', false)
                ->assertDontSee('name="objectives[]"', false)
                ->assertDontSee('name="channels[]"', false)
                ->assertDontSee('name="hashtags"', false)
                ->assertDontSee('name="notes"', false)
                ->assertSee('name="formats[]"', false)
                ->assertSee('name="title"', false)
                ->assertSee('name="body"', false);
        }
    }

    public function test_hidden_fields_disappear_from_the_grid_columns_filters_and_detail_pages(): void
    {
        [$item] = $this->fullContent();
        [$idea] = $this->fullContent(ContentItemKind::Idea);

        $grid = $this->get(route('content.projects.contents.index', $this->project))->assertOk();
        $grid->assertSee('<th scope="col">Pilar de contenido</th>', false)
            ->assertSee('id="pillar"', false)
            ->assertSee('Nota guardada');

        $this->hide(['pillar', 'formats', 'notes', 'hashtags']);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertDontSee('<th scope="col">Pilar de contenido</th>', false)
            ->assertDontSee('<th scope="col">Tema</th>', false)
            ->assertDontSee('<th scope="col">Formato</th>', false)
            ->assertDontSee('<th scope="col">Notas</th>', false)
            ->assertDontSee('<th scope="col">Hashtags</th>', false)
            ->assertDontSee('id="pillar"', false)
            ->assertDontSee('id="topic"', false)
            ->assertDontSee('id="format"', false)
            ->assertSee('id="channel"', false)
            ->assertSee('<th scope="col">Objetivos</th>', false)
            ->assertDontSee('Pilar guardado')
            ->assertDontSee('Nota guardada')
            ->assertDontSee('#guardado')
            ->assertSee('Contenido completo');

        $this->get(route('content.projects.contents.show', [$this->project, $item]))->assertOk()
            ->assertDontSee('Pilar guardado')
            ->assertDontSee('Tema guardado')
            ->assertDontSee('Formato guardado')
            ->assertDontSee('Nota guardada')
            ->assertDontSee('#guardado')
            ->assertSee('Objetivo guardado')
            ->assertSee('Canal guardado');

        $this->get(route('content.projects.ideas.index', $this->project))->assertOk()
            ->assertDontSee('<th scope="col">Pilar de contenido</th>', false)
            ->assertDontSee('<th scope="col">Notas</th>', false)
            ->assertDontSee('Pilar guardado')
            ->assertDontSee('Nota guardada');

        $this->get(route('content.projects.ideas.show', [$this->project, $idea]))->assertOk()
            ->assertDontSee('Pilar guardado')
            ->assertDontSee('Formato guardado')
            ->assertDontSee('Nota guardada')
            ->assertSee('Canal guardado');
    }

    public function test_filters_on_hidden_catalogs_are_ignored_by_the_grid_query(): void
    {
        $pillarA = ContentPillar::factory()->create(['content_project_id' => $this->project->id]);
        $pillarB = ContentPillar::factory()->create(['content_project_id' => $this->project->id]);
        $format = ContentFormat::factory()->create(['content_project_id' => $this->project->id]);
        ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Uno del pilar A', 'content_pillar_id' => $pillarA->id]);
        $other = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Otro del pilar B', 'content_pillar_id' => $pillarB->id]);
        $other->formats()->sync([$format->id]);

        $this->get(route('content.projects.contents.index', [$this->project, 'pillar' => $pillarA->id]))
            ->assertSee('Uno del pilar A')->assertDontSee('Otro del pilar B');

        $this->hide(['pillar', 'formats']);

        $this->get(route('content.projects.contents.index', [$this->project, 'pillar' => $pillarA->id]))
            ->assertOk()->assertSee('Uno del pilar A')->assertSee('Otro del pilar B');
        $this->get(route('content.projects.contents.index', [$this->project, 'format' => $format->id]))
            ->assertOk()->assertSee('Uno del pilar A')->assertSee('Otro del pilar B');
    }

    public function test_stored_data_survives_an_update_while_hidden_and_reappears_when_shown_again(): void
    {
        [$item, $pillar, $topic, $objective, $format, $channel] = $this->fullContent();
        $foreignPillar = ContentPillar::factory()->create();
        $otherObjective = ContentObjective::factory()->create(['content_project_id' => $this->project->id]);

        $this->hide(array_keys(ContentProject::HIDEABLE_STANDARD_FIELDS));

        // Submitted values for hidden fields (even invalid ones) are ignored.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload([
            'revision' => $item->fresh()->revision,
            'title' => 'Título editado',
            'content_pillar_id' => $foreignPillar->id,
            'content_topic_id' => '',
            'objectives' => [$otherObjective->id],
            'formats' => [],
            'hashtags' => '',
            'notes' => 'Nota nueva',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame('Título editado', $fresh->title);
        $this->assertSame($pillar->id, $fresh->content_pillar_id);
        $this->assertSame($topic->id, $fresh->content_topic_id);
        $this->assertSame('#guardado', $fresh->hashtags);
        $this->assertSame('Nota guardada', $fresh->notes);
        $this->assertSame([$objective->id], $fresh->objectives()->pluck('content_objectives.id')->all());
        $this->assertSame([$format->id], $fresh->formats()->pluck('content_formats.id')->all());
        $this->assertSame([$channel->id], $fresh->channels()->pluck('content_channels.id')->all());

        // Re-enabled: the values are back on the detail and the edit form.
        $this->put(route('content.fields.standard.update', $this->project), $this->settings([]))->assertSessionHasNoErrors();

        $this->get(route('content.projects.contents.show', [$this->project, $item]))->assertOk()
            ->assertSee('Pilar guardado')->assertSee('Tema guardado')->assertSee('Objetivo guardado')
            ->assertSee('Formato guardado')->assertSee('Canal guardado')->assertSee('#guardado')->assertSee('Nota guardada');
        $this->get(route('content.projects.contents.edit', [$this->project, $item]))->assertOk()
            ->assertSee('<option value="'.$pillar->id.'" selected', false)
            ->assertSee('value="#guardado"', false);
    }

    public function test_an_idea_update_keeps_hidden_values_and_converts_normally(): void
    {
        [$idea, $pillar, , $objective] = $this->fullContent(ContentItemKind::Idea);
        $this->hide(['pillar', 'objectives', 'notes']);

        $this->put(route('content.projects.ideas.update', [$this->project, $idea]), [
            'revision' => $idea->fresh()->revision,
            'title' => 'Idea editada',
            'body' => 'Copy editado.',
            'content_pillar_id' => '',
            'objectives' => [],
            'notes' => '',
            'hashtags' => '#nuevo',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $idea->fresh();
        $this->assertSame('Idea editada', $fresh->title);
        $this->assertSame($pillar->id, $fresh->content_pillar_id);
        $this->assertSame('Nota guardada', $fresh->notes);
        $this->assertSame('#nuevo', $fresh->hashtags);
        $this->assertSame([$objective->id], $fresh->objectives()->pluck('content_objectives.id')->all());

        $this->post(route('content.projects.ideas.convert', [$this->project, $idea]), [
            'planned_date' => '2026-08-01',
            'planned_time' => '10:00',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($pillar->id, $idea->fresh()->content_pillar_id);
        $this->assertTrue($idea->fresh()->isContent());
    }

    public function test_validation_ignores_hidden_fields_on_creation(): void
    {
        $this->hide(['pillar', 'channels', 'notes']);
        $foreignPillar = ContentPillar::factory()->create();
        $foreignChannel = ContentChannel::factory()->create();

        $this->post(route('content.projects.contents.store', $this->project), $this->payload([
            'title' => 'Creado con campos ocultos',
            'content_pillar_id' => $foreignPillar->id,
            'content_topic_id' => 'no-es-un-id',
            'channels' => ['x'],
            'notes' => ['no', 'texto'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $created = ContentItem::query()->where('title', 'Creado con campos ocultos')->sole();
        $this->assertNull($created->content_pillar_id);
        $this->assertNull($created->content_topic_id);
        $this->assertNull($created->notes);
        $this->assertSame(0, $created->channels()->count());

        $this->post(route('content.projects.ideas.store', $this->project), [
            'title' => 'Idea con campos ocultos',
            'body' => 'Copy.',
            'content_pillar_id' => $foreignPillar->id,
            'channels' => [$foreignChannel->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $idea = ContentItem::query()->where('title', 'Idea con campos ocultos')->sole();
        $this->assertNull($idea->content_pillar_id);
        $this->assertSame(0, $idea->channels()->count());

        // A visible field keeps its validation.
        $this->post(route('content.projects.contents.store', $this->project), $this->payload([
            'formats' => [ContentFormat::factory()->create()->id],
        ]))->assertSessionHasErrors('formats');
    }
}
