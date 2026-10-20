<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use App\Services\Content\ContentAttachmentService;
use App\Services\Content\ContentFieldValueService;
use App\Services\Content\ContentItemService;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Content module review fixes (T2): forms, contents, ideas and attachments.
 *
 * Every round-trip test reads the HTML the server actually rendered, parses
 * the form the way a browser would submit it and sends exactly that payload
 * back, so a selection the form fails to keep selected is caught as data loss
 * instead of being hidden by a hand-built payload.
 */
class ContentFormReviewFixesTest extends TestCase
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
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update', 'content_projects.archive']);
        $this->project->members()->attach($this->manager);
        $this->actingAs($this->manager);
    }

    // ---------------------------------------------------------------- S5

    public function test_editing_a_content_keeps_its_retired_selections_through_the_rendered_form(): void
    {
        [$item, $catalog] = $this->itemWithCatalog(ContentItem::factory()->for($this->project, 'project')->create());
        $this->retire($catalog);

        $html = $this->get(route('content.projects.contents.edit', [$this->project, $item]))
            ->assertOk()
            ->assertSee('(Retirada)')
            ->getContent();

        $pairs = $this->formPairs($html, 'content-item-form');
        $this->assertSame([(string) $catalog['pillar']->id], $this->valuesOf($pairs, 'content_pillar_id'));
        $this->assertSame([(string) $catalog['topic']->id], $this->valuesOf($pairs, 'content_topic_id'));
        $this->assertSame([(string) $catalog['objective']->id], $this->valuesOf($pairs, 'objectives[]'));
        $this->assertSame([(string) $catalog['format']->id], $this->valuesOf($pairs, 'formats[]'));
        $this->assertSame([(string) $catalog['channel']->id], $this->valuesOf($pairs, 'channels[]'));

        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payloadFrom($pairs))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $item->fresh()->load(['objectives', 'formats', 'channels']);
        $this->assertSame($catalog['pillar']->id, $fresh->content_pillar_id);
        $this->assertSame($catalog['topic']->id, $fresh->content_topic_id);
        $this->assertSame([$catalog['objective']->id], $fresh->objectives->pluck('id')->all());
        $this->assertSame([$catalog['format']->id], $fresh->formats->pluck('id')->all());
        $this->assertSame([$catalog['channel']->id], $fresh->channels->pluck('id')->all());
    }

    public function test_editing_an_idea_keeps_its_retired_selections_through_the_rendered_form(): void
    {
        [$idea, $catalog] = $this->itemWithCatalog(ContentItem::factory()->for($this->project, 'project')->idea()->create());
        $this->retire($catalog);

        $html = $this->get(route('content.projects.ideas.edit', [$this->project, $idea]))
            ->assertOk()
            ->assertSee('(Retirada)')
            ->getContent();

        $pairs = $this->formPairs($html, 'content-idea-form');
        $this->put(route('content.projects.ideas.update', [$this->project, $idea]), $this->payloadFrom($pairs))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $idea->fresh()->load(['objectives', 'formats', 'channels']);
        $this->assertSame($catalog['pillar']->id, $fresh->content_pillar_id);
        $this->assertSame($catalog['topic']->id, $fresh->content_topic_id);
        $this->assertSame([$catalog['objective']->id], $fresh->objectives->pluck('id')->all());
        $this->assertSame([$catalog['format']->id], $fresh->formats->pluck('id')->all());
        $this->assertSame([$catalog['channel']->id], $fresh->channels->pluck('id')->all());
    }

    public function test_creation_offers_only_active_options_and_a_newly_chosen_retired_option_is_refused(): void
    {
        [$item, $catalog] = $this->itemWithCatalog(ContentItem::factory()->for($this->project, 'project')->create());
        $retiredObjective = ContentObjective::factory()->for($this->project, 'project')->create(['name' => 'Objetivo jubilado', 'is_active' => false]);

        $this->get(route('content.projects.contents.create', $this->project))->assertOk()
            ->assertDontSee('Objetivo jubilado')
            ->assertDontSee('(Retirada)');

        $this->put(route('content.projects.contents.update', [$this->project, $item]), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Con un objetivo retirado nuevo',
            'body' => 'Texto',
            'revision' => $item->fresh()->revision,
            'objectives' => [$catalog['objective']->id, $retiredObjective->id],
        ])->assertSessionHasErrors('objectives');
    }

    // ---------------------------------------------------------------- S6

    public function test_failed_validation_re_renders_every_submitted_multi_select(): void
    {
        $objectives = ContentObjective::factory()->count(2)->for($this->project, 'project')->create();
        $format = ContentFormat::factory()->for($this->project, 'project')->create();
        $channel = ContentChannel::factory()->for($this->project, 'project')->create();
        $field = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::MultipleSelection)->create(['key' => 'audiencias']);
        $options = ContentFieldOption::factory()->count(2)->for($field, 'definition')->create();

        $create = route('content.projects.contents.create', $this->project);
        $this->from($create)->post(route('content.projects.contents.store', $this->project), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => '',
            'body' => 'Texto',
            'objectives' => [(string) $objectives[1]->id],
            'formats' => [(string) $format->id],
            'channels' => [(string) $channel->id],
            'fields' => ['audiencias' => ['', (string) $options[1]->id]],
        ])->assertRedirect($create)->assertSessionHasErrors('title');

        $pairs = $this->formPairs($this->get($create)->getContent(), 'content-item-form');
        $this->assertSame([(string) $objectives[1]->id], $this->valuesOf($pairs, 'objectives[]'));
        $this->assertSame([(string) $format->id], $this->valuesOf($pairs, 'formats[]'));
        $this->assertSame([(string) $channel->id], $this->valuesOf($pairs, 'channels[]'));
        $this->assertContains((string) $options[1]->id, $this->valuesOf($pairs, 'fields[audiencias][]'));
        $this->assertNotContains((string) $options[0]->id, $this->valuesOf($pairs, 'fields[audiencias][]'));
    }

    public function test_a_stale_idea_save_re_renders_the_submitted_multi_selects(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create();
        $objective = ContentObjective::factory()->for($this->project, 'project')->create();
        $edit = route('content.projects.ideas.edit', [$this->project, $idea]);

        $this->from($edit)->put(route('content.projects.ideas.update', [$this->project, $idea]), [
            'title' => 'Idea',
            'body' => 'Texto',
            'revision' => $idea->fresh()->revision + 5,
            'objectives' => [(string) $objective->id],
        ])->assertRedirect($edit)->assertSessionHasErrors('revision');

        // The warning itself is asserted on the session: with JSON session
        // serialization the in-memory test store drops a flashed error bag on
        // the next request, while old input survives.
        $this->assertStringContainsString('cambió desde que lo abrió', session('errors')->first('revision'));
        $pairs = $this->formPairs($this->get($edit)->getContent(), 'content-idea-form');
        $this->assertSame([(string) $objective->id], $this->valuesOf($pairs, 'objectives[]'));
    }

    // ---------------------------------------------------------------- S7

    public function test_a_multi_select_custom_field_can_be_cleared_from_the_form(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $field = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::MultipleSelection)->create(['key' => 'audiencias']);
        $option = ContentFieldOption::factory()->for($field, 'definition')->create();
        app(ContentFieldValueService::class)->save($item, ['audiencias' => [$option->id]]);

        $pairs = $this->formPairs(
            $this->get(route('content.projects.contents.edit', [$this->project, $item]))->getContent(),
            'content-item-form',
        );
        $this->assertContains((string) $option->id, $this->valuesOf($pairs, 'fields[audiencias][]'));

        // The user unticks every option: the browser then submits only the
        // hidden empty entry the form keeps for this field.
        $pairs = array_values(array_filter(
            $pairs,
            fn (array $pair): bool => ! ($pair[0] === 'fields[audiencias][]' && $pair[1] !== ''),
        ));

        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payloadFrom($pairs))
            ->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('audiencias', app(ContentFieldValueService::class)->values($item->fresh()));
    }

    // ---------------------------------------------------------------- S8

    public function test_an_invalid_month_never_breaks_the_grid_or_the_exports(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create();

        foreach (['2026-13', '2026-00', '2026-1', 'abcd-ef'] as $month) {
            foreach (['content.projects.contents.index', 'content.projects.contents.export.excel', 'content.projects.contents.export.pdf'] as $route) {
                $response = $this->get(route($route, $this->project).'?month='.$month.'&q=hola');

                $this->assertNotSame(500, $response->getStatusCode(), $route.' month='.$month);
                $response->assertRedirect(route('content.projects.contents.index', $this->project).'?q=hola');
                $response->assertSessionHasErrors('month');
            }
        }

        // The grid shows the refusal to the user.
        $this->get(route('content.projects.contents.index', $this->project).'?month=2026-13')
            ->assertRedirect();
        $this->get(route('content.projects.contents.index', $this->project))
            ->assertOk()
            ->assertSee('El mes indicado no es válido');

        // A valid month still filters.
        $this->get(route('content.projects.contents.index', $this->project).'?month=2026-12')->assertOk();
    }

    public function test_the_grid_query_ignores_an_impossible_month_instead_of_failing(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $service = app(ContentItemService::class);

        foreach (['2026-13', '2026-00', '0000-99'] as $month) {
            $this->assertSame([$item->id], $service->query($this->project, ['month' => $month])->pluck('id')->all());
        }
    }

    // ---------------------------------------------------------------- S9

    public function test_content_routes_refuse_an_idea_id(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create();
        $ids = ['contentProject' => $this->project, 'contentItem' => $idea->id];

        $this->get(route('content.projects.contents.show', $ids))->assertNotFound();
        $this->get(route('content.projects.contents.edit', $ids))->assertNotFound();
        $this->put(route('content.projects.contents.update', $ids), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Robado',
            'body' => 'Texto',
            'revision' => $idea->fresh()->revision,
        ])->assertNotFound();
        $this->put(route('content.projects.contents.reschedule', $ids), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'revision' => $idea->fresh()->revision,
        ])->assertNotFound();
        $this->post(route('content.projects.contents.duplicate', $ids))->assertNotFound();

        Storage::fake(ContentAttachmentService::DISK);
        $this->post(route('content.projects.contents.attachments.store', $ids), [
            'file' => UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()),
        ])->assertNotFound();
        $this->post(route('content.projects.contents.attachments.link', $ids), [
            'url' => 'https://example.com/foto.jpg',
        ])->assertNotFound();

        $fresh = $idea->fresh();
        $this->assertTrue($fresh->isIdea());
        $this->assertNull($fresh->planned_at);
        $this->assertNotSame('Robado', $fresh->title);
        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(0, ContentAttachment::query()->count());
    }

    public function test_idea_routes_refuse_a_content_id(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Contenido']);
        $ids = ['contentProject' => $this->project, 'contentIdea' => $content->id];

        $this->get(route('content.projects.ideas.show', $ids))->assertNotFound();
        $this->get(route('content.projects.ideas.edit', $ids))->assertNotFound();
        $this->put(route('content.projects.ideas.update', $ids), [
            'title' => 'Robado',
            'body' => 'Texto',
            'revision' => $content->fresh()->revision,
        ])->assertNotFound();
        $this->post(route('content.projects.ideas.convert', $ids), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
        ])->assertNotFound();

        $this->assertSame('Contenido', $content->fresh()->title);
    }

    // ---------------------------------------------------------------- S10

    public function test_ideas_are_protected_against_concurrent_edits(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Original']);

        $this->get(route('content.projects.ideas.edit', [$this->project, $idea]))->assertOk()
            ->assertSee('name="revision" value="'.$idea->fresh()->revision.'"', false);

        // Without the token the save is refused instead of last-write-wins.
        $this->put(route('content.projects.ideas.update', [$this->project, $idea]), ['title' => 'Sin token', 'body' => 'Texto'])
            ->assertSessionHasErrors('revision');

        // The first editor saves with the current revision.
        $this->put(route('content.projects.ideas.update', [$this->project, $idea]), [
            'title' => 'Primera edición', 'body' => 'Texto', 'revision' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $idea->fresh()->revision);

        // The second editor still holds revision 1 and is warned.
        $edit = route('content.projects.ideas.edit', [$this->project, $idea]);
        $this->from($edit)->put(route('content.projects.ideas.update', [$this->project, $idea]), [
            'title' => 'Segunda edición', 'body' => 'Texto', 'revision' => 1,
        ])->assertRedirect($edit)->assertSessionHasErrors('revision');

        $this->assertSame('Primera edición', $idea->fresh()->title);
        $this->assertStringContainsString('cambió desde que lo abrió', session('errors')->first('revision'));
    }

    // ---------------------------------------------------------------- S12

    public function test_topic_options_carry_their_pillar_for_client_side_filtering(): void
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();

        foreach ([
            route('content.projects.contents.create', $this->project),
            route('content.projects.ideas.create', $this->project),
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('value="'.$topic->id.'" data-pillar-id="'.$pillar->id.'"', false)
                ->assertSee('data-topic-filter', false);
        }
    }

    // ---------------------------------------------------------------- S14

    public function test_duplicate_succeeds_after_a_required_field_was_added_later(): void
    {
        $source = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Original']);
        $optional = ContentFieldDefinition::factory()->for($this->project, 'project')->create(['key' => 'opcional']);
        app(ContentFieldValueService::class)->save($source, ['opcional' => 'Valor']);
        ContentFieldDefinition::factory()->for($this->project, 'project')->create(['key' => 'nuevo_obligatorio', 'required' => true]);

        $this->post(route('content.projects.contents.duplicate', [$this->project, $source]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $copy = ContentItem::query()->where('title', 'Original (copia)')->firstOrFail();
        $this->assertSame(['opcional' => 'Valor'], app(ContentFieldValueService::class)->values($copy));
        $this->assertSame($optional->id, $copy->fieldValues()->value('content_field_definition_id'));
    }

    public function test_a_duplication_failure_is_shown_to_the_user(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $source = ContentItem::factory()->for($this->project, 'project')->create();
        $attachment = app(ContentAttachmentService::class)
            ->upload($source, UploadedFile::fake()->createWithContent('uno.jpg', $this->jpeg()));
        Storage::disk(ContentAttachmentService::DISK)->delete($attachment->path);

        $show = route('content.projects.contents.show', [$this->project, $source]);
        $this->from($show)->post(route('content.projects.contents.duplicate', [$this->project, $source]))
            ->assertRedirect($show)
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'No se pudo duplicar'));

        $this->assertSame(1, ContentItem::query()->count());
    }

    // ---------------------------------------------------------------- S16

    public function test_attachment_ceiling_holds_for_sequential_uploads_and_links(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        config(['content.attachments.max_per_content' => 2]);
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $route = route('content.projects.contents.attachments.store', [$this->project, $item]);

        $this->post($route, ['file' => UploadedFile::fake()->createWithContent('a.jpg', $this->jpeg())])->assertSessionHasNoErrors();
        $this->post(route('content.projects.contents.attachments.link', [$this->project, $item]), ['url' => 'https://example.com/v'])
            ->assertSessionHasNoErrors();
        $this->post($route, ['file' => UploadedFile::fake()->createWithContent('c.jpg', $this->jpeg())])->assertSessionHasErrors('file');

        $this->assertSame([1, 2], ContentAttachment::query()->orderBy('sort')->pluck('sort')->all());
        $this->assertCount(1, Storage::disk(ContentAttachmentService::DISK)->allFiles());
    }

    public function test_a_stored_file_is_removed_when_its_row_cannot_be_inserted(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = ContentItem::factory()->for($this->project, 'project')->create();

        ContentAttachment::creating(function (): void {
            throw new \RuntimeException('insert failed');
        });

        try {
            app(ContentAttachmentService::class)->upload($item, UploadedFile::fake()->createWithContent('a.jpg', $this->jpeg()));
            $this->fail('The upload should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('insert failed', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(ContentAttachmentService::DISK)->allFiles());
        $this->assertSame(0, ContentAttachment::query()->count());
    }

    // ---------------------------------------------------------------- S18

    public function test_idea_detail_offers_the_trash_action(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create();

        $this->get(route('content.projects.ideas.show', [$this->project, $idea]))->assertOk()
            ->assertSee('data-testid="btn-trash-content-idea"', false)
            ->assertSee(route('content.projects.contents.destroy', [$this->project, $idea]), false)
            ->assertSee('Enviar a la papelera');
    }

    // ---------------------------------------------------------------- S19

    public function test_grid_and_detail_show_peru_dates_as_day_month_year_and_the_atrasado_label(): void
    {
        $this->travelTo(PeruTime::toUtc('2026-03-09 10:00:00'));
        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 23:30:00'),
        ]);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('08/03/2026')
            ->assertSee('23:30')
            ->assertSee('Atrasado')
            ->assertDontSee('Vencido')
            ->assertDontSee('2026-03-08');

        $this->get(route('content.projects.contents.show', [$this->project, $item]))->assertOk()
            ->assertSee('08/03/2026')
            ->assertSee('Atrasado')
            ->assertDontSee('Vencido');
    }

    // ---------------------------------------------------------------- S21

    public function test_planned_dates_outside_2000_2099_are_refused_everywhere(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create();

        foreach (['1999-12-31', '2100-01-01', '9999-01-01'] as $date) {
            $this->post(route('content.projects.contents.store', $this->project), [
                'planned_date' => $date, 'planned_time' => '09:30', 'status' => ContentItemStatus::Incomplete->value,
                'title' => 'Fuera de rango', 'body' => 'Texto',
            ])->assertSessionHasErrors('planned_date');

            $this->put(route('content.projects.contents.update', [$this->project, $item]), [
                'planned_date' => $date, 'planned_time' => '09:30', 'status' => ContentItemStatus::Incomplete->value,
                'title' => 'Fuera de rango', 'body' => 'Texto', 'revision' => $item->fresh()->revision,
            ])->assertSessionHasErrors('planned_date');

            $this->put(route('content.projects.contents.reschedule', [$this->project, $item]), [
                'planned_date' => $date, 'planned_time' => '09:30', 'revision' => $item->fresh()->revision,
            ])->assertSessionHasErrors('planned_date');

            $this->post(route('content.projects.ideas.convert', [$this->project, $idea]), [
                'planned_date' => $date, 'planned_time' => '09:30',
            ])->assertSessionHasErrors('planned_date');
        }

        foreach (['2000-01-01', '2099-12-31'] as $date) {
            $this->post(route('content.projects.contents.store', $this->project), [
                'planned_date' => $date, 'planned_time' => '09:30', 'status' => ContentItemStatus::Incomplete->value,
                'title' => 'En rango '.$date, 'body' => 'Texto',
            ])->assertSessionHasNoErrors();
        }

        $this->assertTrue($idea->fresh()->isIdea());
    }

    public function test_numeric_custom_values_must_fit_decimal_14_2(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Number)->create(['key' => 'monto']);
        $service = app(ContentFieldValueService::class);

        foreach (['1000000000000', '999999999999.999', '1.234', '-1000000000000', '1e20'] as $value) {
            try {
                $service->save($item, ['monto' => $value]);
                $this->fail('Expected '.$value.' to be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('monto', $exception->errors(), $value);
            }
        }

        foreach (['999999999999.99', '-999999999999.99', '12.5', '7'] as $value) {
            $service->save($item, ['monto' => $value]);
        }
        $this->assertSame('7.00', (string) $service->values($item->fresh())['monto']);

        // The same refusal reaches the form as a field error.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), [
            'planned_date' => '2026-07-15', 'planned_time' => '09:30', 'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Monto', 'body' => 'Texto', 'revision' => $item->fresh()->revision,
            'fields' => ['monto' => '1000000000000'],
        ])->assertSessionHasErrors('fields.monto');
    }

    // ---------------------------------------------------------------- S23

    public function test_attachment_download_forbids_mime_sniffing(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $attachment = app(ContentAttachmentService::class)
            ->upload($item, UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()));

        $this->get(route('content.projects.contents.attachments.download', [$this->project, $item, $attachment]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ---------------------------------------------------------------- S24

    public function test_ideas_are_listed_newest_first(): void
    {
        $older = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Idea antigua']);
        $newer = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Idea reciente']);

        $this->assertSame([$newer->id, $older->id], app(ContentItemService::class)->ideasQuery($this->project)->pluck('id')->all());
        $this->get(route('content.projects.ideas.index', $this->project))->assertOk()
            ->assertSeeInOrder(['Idea reciente', 'Idea antigua']);
    }

    public function test_grid_search_treats_percent_and_underscore_literally(): void
    {
        $percent = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Descuento 50% hoy', 'body' => 'x']);
        $underscore = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'mi_marca', 'body' => 'x']);
        $plain = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Descuento 50 hoy mismo mixmarca', 'body' => 'x']);
        $service = app(ContentItemService::class);

        $this->assertSame([$percent->id], $service->query($this->project, ['q' => '50%'])->pluck('id')->all());
        $this->assertSame([$underscore->id], $service->query($this->project, ['q' => 'mi_marca'])->pluck('id')->all());
        $this->assertSame([], $service->query($this->project, ['q' => '%'])->whereKey($plain->id)->pluck('id')->all());
    }

    public function test_create_and_duplicate_forms_are_protected_against_double_submit(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();

        $formTag = fn (string $html, string $testId): string => preg_match('/<form\b[^>]*data-testid="'.$testId.'"[^>]*>/s', $html, $match) === 1 ? $match[0] : '';

        $this->assertStringContainsString('data-swal-loading', $formTag(
            $this->get(route('content.projects.contents.create', $this->project))->getContent(), 'content-item-form'));
        $this->assertStringContainsString('data-swal-loading', $formTag(
            $this->get(route('content.projects.ideas.create', $this->project))->getContent(), 'content-idea-form'));
        $this->assertStringContainsString('data-swal-loading', $formTag(
            $this->get(route('content.projects.contents.show', [$this->project, $item]))->getContent(), 'content-item-duplicate-form'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Attach one pillar, topic, objective, format and channel to the row.
     *
     * @return array{0: ContentItem, 1: array{pillar: ContentPillar, topic: ContentTopic, objective: ContentObjective, format: ContentFormat, channel: ContentChannel}}
     */
    private function itemWithCatalog(ContentItem $item): array
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create(['name' => 'Pilar histórico']);
        $catalog = [
            'pillar' => $pillar,
            'topic' => ContentTopic::factory()->for($pillar, 'pillar')->create(['name' => 'Tema histórico']),
            'objective' => ContentObjective::factory()->for($this->project, 'project')->create(['name' => 'Objetivo histórico']),
            'format' => ContentFormat::factory()->for($this->project, 'project')->create(['name' => 'Formato histórico']),
            'channel' => ContentChannel::factory()->for($this->project, 'project')->create(['name' => 'Canal histórico']),
        ];

        $item->forceFill(['content_pillar_id' => $pillar->id, 'content_topic_id' => $catalog['topic']->id])->save();
        $item->objectives()->sync([$catalog['objective']->id]);
        $item->formats()->sync([$catalog['format']->id]);
        $item->channels()->sync([$catalog['channel']->id]);

        // An active alternative in each group, so the form has a real choice.
        $active = ContentPillar::factory()->for($this->project, 'project')->create(['name' => 'Pilar vigente']);
        ContentTopic::factory()->for($active, 'pillar')->create(['name' => 'Tema vigente']);
        ContentObjective::factory()->for($this->project, 'project')->create(['name' => 'Objetivo vigente']);

        return [$item->fresh(), $catalog];
    }

    /**
     * @param  array<string, \App\Models\Content\ContentCatalog>  $catalog
     */
    private function retire(array $catalog): void
    {
        foreach ($catalog as $option) {
            $option->deactivate();
        }
    }

    /**
     * The name/value pairs a browser would submit for the form, in order.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function formPairs(string $html, string $testId): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $form = $xpath->query('//form[@data-testid="'.$testId.'"]')->item(0);
        $this->assertNotNull($form, 'Form '.$testId.' not rendered.');

        $pairs = [];
        foreach ($xpath->query('.//input|.//textarea|.//select', $form) as $element) {
            /** @var \DOMElement $element */
            $name = $element->getAttribute('name');
            if ($name === '' || $element->hasAttribute('disabled')) {
                continue;
            }

            if ($element->nodeName === 'input') {
                $type = strtolower($element->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'file'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true) && ! $element->hasAttribute('checked')) {
                    continue;
                }
                $pairs[] = [$name, $element->getAttribute('value')];

                continue;
            }

            if ($element->nodeName === 'textarea') {
                $pairs[] = [$name, $element->textContent];

                continue;
            }

            $options = iterator_to_array($xpath->query('.//option', $element));
            $selected = array_values(array_filter($options, fn (\DOMElement $option): bool => $option->hasAttribute('selected')));
            if ($selected === [] && ! $element->hasAttribute('multiple') && $options !== []) {
                $selected = [$options[0]];
            }
            foreach ($selected as $option) {
                /** @var \DOMElement $option */
                $pairs[] = [$name, $option->getAttribute('value')];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return list<string>
     */
    private function valuesOf(array $pairs, string $name): array
    {
        return array_values(array_map(
            fn (array $pair): string => $pair[1],
            array_filter($pairs, fn (array $pair): bool => $pair[0] === $name && ! ($pair[1] === '' && str_ends_with($name, '[]'))),
        ));
    }

    /**
     * Decode the pairs exactly as PHP decodes a submitted body.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, mixed>
     */
    private function payloadFrom(array $pairs): array
    {
        $body = implode('&', array_map(
            fn (array $pair): string => urlencode($pair[0]).'='.urlencode($pair[1]),
            array_filter($pairs, fn (array $pair): bool => ! in_array($pair[0], ['_token', '_method'], true)),
        ));
        parse_str($body, $data);

        return $data;
    }

    private function jpeg(int $bytes = 64): string
    {
        return "\xFF\xD8\xFF".str_repeat("\x00", max(0, $bytes - 3));
    }
}
