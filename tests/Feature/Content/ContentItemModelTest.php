<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use App\Support\Content\PeruTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T6a — content item model behaviour.
 *
 * Covers creation with the specification fields and relations, the idea versus
 * content distinction, the Peru time round-trip, the derived weekday, the
 * overdue criterion and the ordered default scope.
 */
class ContentItemModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_content_item_is_created_per_project_with_the_specification_fields(): void
    {
        $project = ContentProject::factory()->create();
        $pillar = ContentPillar::factory()->for($project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $objectives = ContentObjective::factory()->for($project, 'project')->count(2)->create();
        $format = ContentFormat::factory()->for($project, 'project')->create();
        $channel = ContentChannel::factory()->for($project, 'project')->create();

        $item = ContentItem::factory()->for($project, 'project')->create([
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'status' => ContentItemStatus::InProgress,
            'title' => 'Lanzamiento de temporada',
            'body' => 'Texto de copia larga para la publicación.',
            'hashtags' => '#Maia #Consultores',
            'notes' => 'Notas internas del equipo.',
        ]);

        $item->objectives()->attach($objectives->pluck('id')->all());
        $item->formats()->attach($format->id);
        $item->channels()->attach($channel->id);

        $fresh = $item->fresh();

        $this->assertTrue($fresh->project->is($project));
        $this->assertTrue($fresh->pillar->is($pillar));
        $this->assertTrue($fresh->topic->is($topic));
        $this->assertSame(ContentItemStatus::InProgress, $fresh->status);
        $this->assertSame('Lanzamiento de temporada', $fresh->title);
        $this->assertSame('Texto de copia larga para la publicación.', $fresh->body);
        $this->assertSame('#Maia #Consultores', $fresh->hashtags);
        $this->assertSame('Notas internas del equipo.', $fresh->notes);
        $this->assertNotNull($fresh->planned_at);

        $this->assertSame(2, $fresh->objectives()->count());
        $this->assertTrue($fresh->objectives->contains('id', $objectives->first()->id));
        $this->assertSame(1, $fresh->formats()->count());
        $this->assertSame(1, $fresh->channels()->count());
        $this->assertTrue($fresh->formats->first()->is($format));
        $this->assertTrue($fresh->channels->first()->is($channel));
    }

    public function test_hashtags_and_notes_are_optional(): void
    {
        $item = ContentItem::factory()->create(['hashtags' => null, 'notes' => null]);

        $fresh = $item->fresh();
        $this->assertNull($fresh->hashtags);
        $this->assertNull($fresh->notes);
    }

    public function test_an_idea_saves_without_a_planned_instant_and_is_distinguishable(): void
    {
        $idea = ContentItem::factory()->idea()->create();
        $content = ContentItem::factory()->create();

        $this->assertNull($idea->planned_at);
        $this->assertTrue($idea->isIdea());
        $this->assertFalse($idea->isContent());
        $this->assertNull($idea->plannedWeekday());
        $this->assertNull($idea->plannedDate());
        $this->assertFalse($idea->isOverdue());

        $this->assertNotNull($content->planned_at);
        $this->assertTrue($content->isContent());
        $this->assertFalse($content->isIdea());

        $this->assertSame(1, ContentItem::query()->ideas()->count());
        $this->assertSame(1, ContentItem::query()->contents()->count());
    }

    public function test_derived_weekday_uses_the_peruvian_day_even_when_utc_differs(): void
    {
        // 2026-03-08 23:30 in Peru is 2026-03-09 04:30 UTC: Sunday in Peru,
        // Monday in UTC. A naive UTC weekday would be wrong.
        $instant = PeruTime::toUtc('2026-03-08 23:30:00');

        $this->assertSame('2026-03-09 04:30:00', $instant->format('Y-m-d H:i:s'));
        $this->assertSame(1, $instant->dayOfWeekIso, 'The UTC day is Monday.');
        $this->assertSame(7, PeruTime::weekday($instant), 'The Peru day is Sunday.');
        $this->assertSame('domingo', PeruTime::weekdayLabel($instant));
        $this->assertSame('2026-03-08', PeruTime::date($instant));

        $item = ContentItem::factory()->create(['planned_at' => $instant]);

        $this->assertSame(7, $item->fresh()->plannedWeekday());
        $this->assertSame('2026-03-08', $item->fresh()->plannedDate());
    }

    public function test_stored_utc_instant_round_trips_to_the_intended_peruvian_date_and_time(): void
    {
        $peruWallClock = '2026-07-15 09:30:00';
        $zone = new \DateTimeZone(PeruTime::TIMEZONE);
        $expected = new \DateTimeImmutable($peruWallClock, $zone);

        $item = ContentItem::factory()->create([
            'planned_at' => PeruTime::toUtc($peruWallClock),
        ]);

        $stored = $item->fresh()->planned_at;

        // Stored as the UTC instant, never as the Peru wall clock.
        $this->assertSame('UTC', $stored->getTimezone()->getName());
        $this->assertSame('2026-07-15 14:30:00', $stored->format('Y-m-d H:i:s'));
        $this->assertSame($expected->getTimestamp(), $stored->getTimestamp());

        $back = PeruTime::toPeru($stored);

        // The offset is read from the IANA timezone, not assumed to be -05:00.
        $this->assertSame($expected->getOffset(), $back->getOffset());
        $this->assertSame($peruWallClock, $back->format('Y-m-d H:i:s'));
        $this->assertSame('-05:00', $back->format('P'));
        $this->assertSame($peruWallClock, $item->fresh()->planned_at->setTimezone(PeruTime::TIMEZONE)->format('Y-m-d H:i:s'));
    }

    public function test_overdue_criterion_excludes_published_contents_and_ideas(): void
    {
        $overdue = ContentItem::factory()->overdue()->create();
        $publishedPast = ContentItem::factory()->overdue()->published()->create();
        $future = ContentItem::factory()->create([
            'planned_at' => now()->addDay(),
            'status' => ContentItemStatus::Incomplete,
        ]);
        $idea = ContentItem::factory()->idea()->create();

        $overdueIds = ContentItem::query()->overdue()->pluck('id')->all();

        $this->assertContains($overdue->id, $overdueIds);
        $this->assertNotContains($publishedPast->id, $overdueIds);
        $this->assertNotContains($future->id, $overdueIds);
        $this->assertNotContains($idea->id, $overdueIds);

        $this->assertTrue($overdue->fresh()->isOverdue());
        $this->assertFalse($publishedPast->fresh()->isOverdue());
        $this->assertFalse($future->fresh()->isOverdue());
        $this->assertFalse($idea->fresh()->isOverdue());
    }

    public function test_published_content_is_never_reported_overdue(): void
    {
        $published = ContentItem::factory()->create([
            'planned_at' => now()->subMonth(),
            'status' => ContentItemStatus::Published,
        ]);

        $this->assertTrue($published->isPublished());
        $this->assertFalse($published->isOverdue());
        $this->assertSame(0, ContentItem::query()->overdue()->count());
    }

    public function test_ordered_default_scope_lists_dated_contents_first_and_ideas_last(): void
    {
        $idea = ContentItem::factory()->idea()->create();
        $later = ContentItem::factory()->create(['planned_at' => '2026-06-10 12:00:00']);
        $earlier = ContentItem::factory()->create(['planned_at' => '2026-06-01 12:00:00']);

        $expected = [$earlier->id, $later->id, $idea->id];

        $this->assertSame($expected, ContentItem::query()->pluck('id')->all());
        $this->assertSame($expected, ContentItem::query()->ordered()->pluck('id')->all());
    }

    public function test_item_rows_record_the_authenticated_actor(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $item = ContentItem::factory()->create();
        $this->assertSame($actor->id, $item->created_by);

        $item->update(['title' => 'Título editado']);
        $this->assertSame($actor->id, $item->fresh()->updated_by);
    }
}
