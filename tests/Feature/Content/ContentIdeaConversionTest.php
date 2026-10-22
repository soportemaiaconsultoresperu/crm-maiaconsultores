<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use App\Services\Content\ContentFieldValueService;
use App\Services\Content\ContentItemService;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * T6d — idea-to-content conversion.
 *
 * Proves the conversion is a single in-place, transactional update: the same row
 * id becomes a content, no second row is created, every field, selection and
 * custom value survives, the planned instant is the submitted Peruvian moment,
 * the status is left untouched, a missing date/time is refused, an
 * already-converted row is refused without a second content, and a failure —
 * including a write forced to fail mid-transaction — leaves the idea exactly as
 * it was.
 */
class ContentIdeaConversionTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function conversionPayload(array $overrides = []): array
    {
        return array_merge([
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
        ], $overrides);
    }

    public function test_conversion_turns_the_same_row_into_one_content_with_the_same_data(): void
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $objective = ContentObjective::factory()->for($this->project, 'project')->create();
        $format = ContentFormat::factory()->for($this->project, 'project')->create();
        $channel = ContentChannel::factory()->for($this->project, 'project')->create();
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'texto', 'label' => 'Texto libre']);

        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create([
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'status' => ContentItemStatus::Incomplete,
            'title' => 'Idea convertible',
            'body' => 'Cuerpo de la idea.',
            'hashtags' => '#Maia',
            'notes' => 'Notas internas.',
        ]);
        $idea->objectives()->attach($objective);
        $idea->formats()->attach($format);
        $idea->channels()->attach($channel);
        app(ContentFieldValueService::class)->save($idea, ['texto' => 'Valor original']);

        $rowCountBefore = ContentItem::query()->count();
        $ideaId = $idea->id;

        $this->post(
            route('content.projects.ideas.convert', ['contentProject' => $this->project, 'contentIdea' => $ideaId]),
            $this->conversionPayload(),
        )->assertRedirect(route('content.projects.contents.show', ['contentProject' => $this->project, 'contentItem' => $ideaId]));

        // Non-duplicating: the row count is unchanged and the same id now holds
        // the content, so nothing was copied or deleted.
        $this->assertSame($rowCountBefore, ContentItem::query()->count());

        $fresh = ContentItem::query()->findOrFail($ideaId);
        $this->assertTrue($fresh->isContent());
        $this->assertFalse($fresh->isIdea());
        $this->assertSame(1, ContentItem::query()->contents()->count());
        $this->assertSame(0, ContentItem::query()->ideas()->count());

        // The planned instant is the submitted Peruvian moment, stored as UTC.
        $this->assertSame(
            PeruTime::toUtc('2026-07-15 09:30:00')->timestamp,
            $fresh->planned_at->timestamp,
        );
        $this->assertSame('2026-07-15 14:30:00', $fresh->planned_at->format('Y-m-d H:i:s'));

        // Status is preserved, not invented: the specification defines no
        // transition for conversion.
        $this->assertSame(ContentItemStatus::Incomplete, $fresh->status);

        // Text, notes, hashtags, pillar, topic and every selection survive.
        $this->assertSame('Idea convertible', $fresh->title);
        $this->assertSame('Cuerpo de la idea.', $fresh->body);
        $this->assertSame('#Maia', $fresh->hashtags);
        $this->assertSame('Notas internas.', $fresh->notes);
        $this->assertSame($pillar->id, $fresh->content_pillar_id);
        $this->assertSame($topic->id, $fresh->content_topic_id);
        $this->assertTrue($fresh->objectives()->whereKey($objective->id)->exists());
        $this->assertTrue($fresh->formats()->whereKey($format->id)->exists());
        $this->assertTrue($fresh->channels()->whereKey($channel->id)->exists());

        // The custom value is still there, unchanged.
        $this->assertSame('Valor original', app(ContentFieldValueService::class)->values($fresh)['texto']);

        // It leaves the ideas section and appears on the grid. Assert on the row
        // markers, not the title, so the success flash cannot mask the check.
        $this->get(route('content.projects.ideas.index', $this->project))->assertOk()
            ->assertDontSee('content-idea-row-'.$ideaId, false);
        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('content-item-row-'.$ideaId, false);
    }

    public function test_conversion_without_a_date_or_time_is_refused(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Sin fecha']);

        $this->from(route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $idea->id]))
            ->post(route('content.projects.ideas.convert', ['contentProject' => $this->project, 'contentIdea' => $idea->id]), [
                'planned_date' => '',
                'planned_time' => '',
            ])
            ->assertSessionHasErrors(['planned_date', 'planned_time']);

        $fresh = $idea->fresh();
        $this->assertTrue($fresh->isIdea());
        $this->assertNull($fresh->planned_at);
    }

    public function test_converting_an_already_converted_row_is_refused_without_a_second_content(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Solo una vez']);
        $url = route('content.projects.ideas.convert', ['contentProject' => $this->project, 'contentIdea' => $idea->id]);

        $this->post($url, $this->conversionPayload())->assertRedirect();
        $this->assertSame(1, ContentItem::query()->contents()->count());

        // The idea no longer exists as an idea, so the second attempt is refused
        // and creates nothing.
        $this->post($url, $this->conversionPayload())->assertNotFound();

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(1, ContentItem::query()->contents()->count());
        $this->assertSame(0, ContentItem::query()->ideas()->count());
    }

    public function test_a_failed_conversion_leaves_the_idea_unchanged(): void
    {
        // The field becomes required after the idea was saved without a value, so
        // the idea no longer satisfies the content contract.
        $field = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'requerido', 'label' => 'Requerido', 'required' => false]);
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create([
            'title' => 'Título original',
            'body' => 'Cuerpo original.',
        ]);
        $field->update(['required' => true]);

        $this->from(route('content.projects.ideas.show', ['contentProject' => $this->project, 'contentIdea' => $idea->id]))
            ->post(route('content.projects.ideas.convert', ['contentProject' => $this->project, 'contentIdea' => $idea->id]), $this->conversionPayload())
            ->assertSessionHasErrors('fields.requerido');

        $fresh = $idea->fresh();
        $this->assertTrue($fresh->isIdea());
        $this->assertNull($fresh->planned_at);
        $this->assertSame('Título original', $fresh->title);
        $this->assertSame('Cuerpo original.', $fresh->body);
        $this->assertSame(0, ContentItem::query()->contents()->count());
    }

    public function test_a_write_failure_mid_conversion_rolls_the_idea_back(): void
    {
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'No convertir']);
        $ideaId = $idea->id;

        // Force the UPDATE itself to fail after it has run, to prove the row is
        // restored by the transaction rather than merely guarded in memory.
        $event = 'eloquent.saved: '.ContentItem::class;
        ContentItem::saved(function (ContentItem $model): void {
            if ($model->title === 'No convertir' && $model->isContent()) {
                throw new \RuntimeException('forced write failure');
            }
        });

        try {
            app(ContentItemService::class)->convertIdea($idea, $this->conversionPayload());
            $this->fail('The forced failure should have aborted the conversion.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced write failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        // Read back from the database: the idea is exactly as it was.
        $fresh = ContentItem::query()->findOrFail($ideaId);
        $this->assertTrue($fresh->isIdea());
        $this->assertNull($fresh->planned_at);
        $this->assertSame('No convertir', $fresh->title);
        $this->assertSame(0, ContentItem::query()->contents()->count());
    }
}
