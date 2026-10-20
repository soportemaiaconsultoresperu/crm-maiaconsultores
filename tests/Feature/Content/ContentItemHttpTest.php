<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentFieldValue;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use App\Services\Content\ContentFieldValueService;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T6c — the content grid and the create/edit form.
 *
 * Exercises the HTTP surface an editor uses: the columns, the search and every
 * filter, the Peru display, the overdue marker, project isolation, the
 * authorization split between reading and mutating, and custom values stored
 * through ContentFieldValueService.
 */
class ContentItemHttpTest extends TestCase
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
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Lanzamiento de temporada',
            'body' => 'Texto de la publicación.',
        ], $overrides);
    }

    public function test_member_with_update_reaches_the_grid_and_the_form(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Contenido existente']);

        $this->get(route('content.projects.show', $this->project))->assertOk()
            ->assertSee(route('content.projects.contents.index', $this->project), false);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('Contenido existente')
            ->assertSee('Gancho o título')
            ->assertSee('Red social o canal')
            ->assertSee('Descripción o copy');

        $this->get(route('content.projects.contents.create', $this->project))->assertOk()
            ->assertSee('Nuevo contenido')
            ->assertSee('Horario en Perú');

        $this->get(route('content.projects.contents.show', ['contentProject' => $this->project, 'contentItem' => $item->id]))->assertOk()
            ->assertSee('Contenido existente');

        $this->get(route('content.projects.contents.edit', ['contentProject' => $this->project, 'contentItem' => $item->id]))->assertOk()
            ->assertSee('Editar contenido');
    }

    public function test_non_member_gets_404_on_the_grid_form_and_actions(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignItem = ContentItem::factory()->for($foreign, 'project')->create();

        $this->get(route('content.projects.contents.index', $foreign))->assertNotFound();
        $this->get(route('content.projects.contents.create', $foreign))->assertNotFound();
        $this->postJson(route('content.projects.contents.store', $foreign), [])->assertNotFound();
        $this->get(route('content.projects.contents.show', ['contentProject' => $foreign, 'contentItem' => $foreignItem->id]))->assertNotFound();
        $this->get(route('content.projects.contents.edit', ['contentProject' => $foreign, 'contentItem' => $foreignItem->id]))->assertNotFound();
        $this->putJson(route('content.projects.contents.update', ['contentProject' => $foreign, 'contentItem' => $foreignItem->id]), [])->assertNotFound();
    }

    /**
     * Review fixes S1/S2: membership alone lets a user work on the project's
     * contents (this test used to assert 403 for a member without
     * `content_projects.update`), while an archived project is read-only:
     * reads stay open and every mutation is refused.
     */
    public function test_plain_member_works_on_contents_and_an_archived_project_is_read_only(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $member->givePermissionTo('content_projects.view');
        $this->project->members()->attach($member);
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Original']);
        $this->actingAs($member);

        $this->get(route('content.projects.contents.create', $this->project))->assertOk();
        $this->get(route('content.projects.contents.edit', ['contentProject' => $this->project, 'contentItem' => $item->id]))->assertOk();
        $this->put(route('content.projects.contents.update', ['contentProject' => $this->project, 'contentItem' => $item->id]), $this->payload([
            'title' => 'Editado por un miembro',
            'revision' => $item->fresh()->revision,
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Editado por un miembro', $item->fresh()->title);

        $this->project->archive();

        // Reading the grid is allowed; the form and every mutation are not.
        $this->get(route('content.projects.contents.index', $this->project))->assertOk();

        $this->get(route('content.projects.contents.create', $this->project))->assertForbidden();
        $this->postJson(route('content.projects.contents.store', $this->project), $this->payload())->assertForbidden();
        $this->get(route('content.projects.contents.edit', ['contentProject' => $this->project, 'contentItem' => $item->id]))->assertForbidden();
        $this->putJson(route('content.projects.contents.update', ['contentProject' => $this->project, 'contentItem' => $item->id]), $this->payload([
            'title' => 'Robado',
            'revision' => $item->fresh()->revision,
        ]))->assertForbidden();

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame('Editado por un miembro', $item->fresh()->title);
    }

    public function test_only_the_addressed_projects_contents_appear_and_foreign_items_are_404(): void
    {
        $mine = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Mío visible']);
        $foreign = ContentProject::factory()->create();
        $theirs = ContentItem::factory()->for($foreign, 'project')->create(['title' => 'Ajeno oculto']);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('Mío visible')
            ->assertDontSee('Ajeno oculto');

        // A foreign child id is resolved inside the addressed project, so it is
        // never readable, editable or writable through this URL.
        $this->get(route('content.projects.contents.show', ['contentProject' => $this->project, 'contentItem' => $theirs->id]))->assertNotFound();
        $this->get(route('content.projects.contents.edit', ['contentProject' => $this->project, 'contentItem' => $theirs->id]))->assertNotFound();
        $this->putJson(route('content.projects.contents.update', ['contentProject' => $this->project, 'contentItem' => $theirs->id]), $this->payload(['title' => 'Robado', 'revision' => $theirs->fresh()->revision]))
            ->assertNotFound();

        $this->assertSame('Ajeno oculto', $theirs->fresh()->title);
    }

    public function test_search_matches_title_and_copy(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Alfa promoción', 'body' => 'cuerpo uno']);
        ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Beta', 'body' => 'cuerpo dos con palabra clave']);
        ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Gamma', 'body' => 'cuerpo tres']);

        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'q' => 'Alfa']))
            ->assertOk()->assertSee('Alfa promoción')->assertDontSee('Beta')->assertDontSee('Gamma');

        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'q' => 'palabra clave']))
            ->assertOk()->assertSee('Beta')->assertDontSee('Alfa promoción')->assertDontSee('Gamma');
    }

    public function test_each_filter_narrows_the_results(): void
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $otherPillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $format = ContentFormat::factory()->for($this->project, 'project')->create();
        $channel = ContentChannel::factory()->for($this->project, 'project')->create();

        $target = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-15 10:00:00'),
            'status' => ContentItemStatus::InProgress,
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'title' => 'Objetivo filtrable',
        ]);
        $target->formats()->attach($format->id);
        $target->channels()->attach($channel->id);

        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-04-15 10:00:00'),
            'status' => ContentItemStatus::Published,
            'content_pillar_id' => $otherPillar->id,
            'content_topic_id' => null,
            'title' => 'Otro contenido',
        ]);

        $assertTargetOnly = function (array $query): void {
            $this->get(route('content.projects.contents.index', ['contentProject' => $this->project] + $query))
                ->assertOk()
                ->assertSee('Objetivo filtrable')
                ->assertDontSee('Otro contenido');
        };

        $assertTargetOnly(['month' => '2026-03']);
        $assertTargetOnly(['status' => ContentItemStatus::InProgress->value]);
        $assertTargetOnly(['pillar' => (string) $pillar->id]);
        $assertTargetOnly(['topic' => (string) $topic->id]);
        $assertTargetOnly(['format' => (string) $format->id]);
        $assertTargetOnly(['channel' => (string) $channel->id]);

        // The complementary value flips the visible row.
        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'month' => '2026-04']))
            ->assertOk()->assertSee('Otro contenido')->assertDontSee('Objetivo filtrable');
        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'status' => ContentItemStatus::Published->value]))
            ->assertOk()->assertSee('Otro contenido')->assertDontSee('Objetivo filtrable');
    }

    public function test_planned_instant_is_displayed_in_peru_time_even_when_the_utc_date_differs(): void
    {
        // 2026-03-08 23:30 in Peru is 2026-03-09 04:30 UTC: Sunday in Peru,
        // Monday in UTC. A naive UTC rendering would show the wrong day.
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 23:30:00'),
            'title' => 'Contenido de medianoche',
        ]);

        // Review fix S19: the Peru calendar day is shown as d/m/Y.
        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('08/03/2026')
            ->assertSee('domingo')
            ->assertSee('23:30')
            ->assertDontSee('09/03/2026')
            ->assertDontSee('lunes')
            ->assertDontSee('04:30');
    }

    public function test_overdue_content_is_marked_and_published_is_not(): void
    {
        $overdue = ContentItem::factory()->for($this->project, 'project')->overdue()->create(['title' => 'Vencido visible']);
        $published = ContentItem::factory()->for($this->project, 'project')->overdue()->published()->create(['title' => 'Publicado a tiempo']);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('content-item-overdue-'.$overdue->id, false)
            ->assertSee('Atrasado')
            ->assertDontSee('content-item-overdue-'.$published->id, false);
    }

    public function test_custom_values_are_stored_through_the_service_and_read_back(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'texto', 'label' => 'Texto libre']);
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Number)->create(['key' => 'numero', 'label' => 'Número']);
        $single = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::SingleSelection)->create(['key' => 'unica', 'label' => 'Selección única']);
        $option = ContentFieldOption::factory()->for($single, 'definition')->create(['label' => 'Opción A']);
        $multiple = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::MultipleSelection)->create(['key' => 'multiple', 'label' => 'Selección múltiple']);
        $options = ContentFieldOption::factory()->count(2)->for($multiple, 'definition')->create();

        $this->post(route('content.projects.contents.store', $this->project), $this->payload([
            'title' => 'Con campos propios',
            'fields' => [
                'texto' => 'Hola',
                'numero' => '1234.50',
                'unica' => $option->id,
                'multiple' => $options->pluck('id')->all(),
            ],
        ]))->assertRedirect();

        $item = ContentItem::query()->where('title', 'Con campos propios')->sole();
        $values = app(ContentFieldValueService::class)->values($item);

        $this->assertSame('Hola', $values['texto']);
        $this->assertSame('1234.50', $values['numero']);
        $this->assertSame($option->id, $values['unica']);
        $this->assertEqualsCanonicalizing($options->pluck('id')->all(), $values['multiple']);
        $this->assertSame(4, ContentFieldValue::query()->count());

        $this->get(route('content.projects.contents.show', ['contentProject' => $this->project, 'contentItem' => $item->id]))
            ->assertOk()
            ->assertSee('Texto libre')
            ->assertSee('Hola')
            ->assertSee('Opción A');
    }

    public function test_a_custom_value_violating_its_type_is_rejected_and_not_stored(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Number)->create(['key' => 'numero', 'label' => 'Número']);

        $this->post(route('content.projects.contents.store', $this->project), $this->payload([
            'title' => 'Inválido',
            'fields' => ['numero' => 'no-es-numero'],
        ]))->assertSessionHasErrors('fields.numero');

        // The rejected value never lands, and neither does the half-written item.
        $this->assertSame(0, ContentFieldValue::query()->count());
        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_foreign_or_mismatched_selections_are_rejected_without_writing(): void
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $otherPillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $topicOfOtherPillar = ContentTopic::factory()->for($otherPillar, 'pillar')->create();

        $foreignProject = ContentProject::factory()->create();
        $foreignObjective = ContentObjective::factory()->for($foreignProject, 'project')->create();
        $foreignFormat = ContentFormat::factory()->for($foreignProject, 'project')->create();
        $foreignChannel = ContentChannel::factory()->for($foreignProject, 'project')->create();

        $this->postJson(route('content.projects.contents.store', $this->project), $this->payload([
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topicOfOtherPillar->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('content_topic_id');

        $this->postJson(route('content.projects.contents.store', $this->project), $this->payload([
            'objectives' => [$foreignObjective->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('objectives');

        $this->postJson(route('content.projects.contents.store', $this->project), $this->payload([
            'formats' => [$foreignFormat->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('formats');

        $this->postJson(route('content.projects.contents.store', $this->project), $this->payload([
            'channels' => [$foreignChannel->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('channels');

        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_member_can_update_a_content_and_its_custom_values(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'texto', 'label' => 'Texto libre']);
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Antes']);
        app(ContentFieldValueService::class)->save($item, ['texto' => 'Valor anterior']);

        $this->put(route('content.projects.contents.update', ['contentProject' => $this->project, 'contentItem' => $item->id]), $this->payload([
            'title' => 'Después',
            'status' => ContentItemStatus::Published->value,
            'fields' => ['texto' => 'Valor nuevo'],
            'revision' => $item->fresh()->revision,
        ]))->assertRedirect(route('content.projects.contents.show', ['contentProject' => $this->project, 'contentItem' => $item->id]));

        $item->refresh();
        $this->assertSame('Después', $item->title);
        $this->assertSame(ContentItemStatus::Published, $item->status);
        $this->assertSame('Valor nuevo', app(ContentFieldValueService::class)->values($item)['texto']);
    }

    public function test_editing_only_the_title_preserves_selections_the_catalog_retired(): void
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $objective = ContentObjective::factory()->for($this->project, 'project')->create();
        $format = ContentFormat::factory()->for($this->project, 'project')->create();
        $channel = ContentChannel::factory()->for($this->project, 'project')->create();

        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'title' => 'Antes',
        ]);
        $item->objectives()->attach($objective);
        $item->formats()->attach($format);
        $item->channels()->attach($channel);

        // The catalog retires every option the content already references.
        $pillar->deactivate();
        $topic->deactivate();
        $objective->deactivate();
        $format->deactivate();
        $channel->deactivate();

        $this->put(route('content.projects.contents.update', ['contentProject' => $this->project, 'contentItem' => $item->id]), $this->payload([
            'title' => 'Después',
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'objectives' => [$objective->id],
            'formats' => [$format->id],
            'channels' => [$channel->id],
            'revision' => $item->fresh()->revision,
        ]))->assertRedirect(route('content.projects.contents.show', ['contentProject' => $this->project, 'contentItem' => $item->id]));

        $item->refresh();
        $this->assertSame('Después', $item->title);
        $this->assertSame($pillar->id, $item->content_pillar_id);
        $this->assertSame($topic->id, $item->content_topic_id);
        $this->assertTrue($item->objectives()->whereKey($objective->id)->exists());
        $this->assertTrue($item->formats()->whereKey($format->id)->exists());
        $this->assertTrue($item->channels()->whereKey($channel->id)->exists());
    }

    public function test_editing_rejects_a_newly_chosen_retired_option(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();

        $retiredPillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $retiredObjective = ContentObjective::factory()->for($this->project, 'project')->create();
        $retiredFormat = ContentFormat::factory()->for($this->project, 'project')->create();
        $retiredChannel = ContentChannel::factory()->for($this->project, 'project')->create();
        $retiredPillar->deactivate();
        $retiredObjective->deactivate();
        $retiredFormat->deactivate();
        $retiredChannel->deactivate();

        $url = route('content.projects.contents.update', ['contentProject' => $this->project, 'contentItem' => $item->id]);
        $revision = $item->fresh()->revision;

        $this->putJson($url, $this->payload(['content_pillar_id' => $retiredPillar->id, 'revision' => $revision]))
            ->assertUnprocessable()->assertJsonValidationErrors('content_pillar_id');
        $this->putJson($url, $this->payload(['objectives' => [$retiredObjective->id], 'revision' => $revision]))
            ->assertUnprocessable()->assertJsonValidationErrors('objectives');
        $this->putJson($url, $this->payload(['formats' => [$retiredFormat->id], 'revision' => $revision]))
            ->assertUnprocessable()->assertJsonValidationErrors('formats');
        $this->putJson($url, $this->payload(['channels' => [$retiredChannel->id], 'revision' => $revision]))
            ->assertUnprocessable()->assertJsonValidationErrors('channels');
    }

    public function test_form_renders_project_custom_fields_grouped_by_section_with_required_marker(): void
    {
        $section = ContentFieldSection::factory()->for($this->project, 'project')->create(['name' => 'Datos generales']);
        ContentFieldDefinition::factory()->for($this->project, 'project')->create([
            'key' => 'obligatorio',
            'label' => 'Campo obligatorio',
            'content_field_section_id' => $section->id,
            'required' => true,
        ]);
        $selection = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::SingleSelection)->create([
                'key' => 'seleccion',
                'label' => 'Campo de selección',
                'content_field_section_id' => $section->id,
            ]);
        ContentFieldOption::factory()->for($selection, 'definition')->create(['label' => 'Opción de lista']);
        ContentFieldDefinition::factory()->for($this->project, 'project')->create([
            'key' => 'suelto',
            'label' => 'Campo suelto',
            'content_field_section_id' => null,
        ]);

        $this->get(route('content.projects.contents.create', $this->project))->assertOk()
            ->assertSee('Campos personalizados')
            ->assertSee('Datos generales')
            ->assertSee('Campo obligatorio')
            ->assertSee('Campo de selección')
            ->assertSee('Opción de lista')
            ->assertSee('Campo suelto')
            ->assertSeeInOrder(['name="fields[obligatorio]"', 'required'], false);
    }
}
