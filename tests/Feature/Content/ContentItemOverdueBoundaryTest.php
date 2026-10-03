<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Support\Content\PeruTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T6d — the overdue boundary rule in Peru time.
 *
 * Proves the "date but no time" case against the Peruvian day rather than the
 * UTC day (at 00:00 UTC the content is still inside its Peru day because Peru is
 * five hours behind), that a timed content is overdue from its own instant, that
 * published rows are never overdue, and that the grid marker follows the same
 * rule it followed before for timed contents.
 */
class ContentItemOverdueBoundaryTest extends TestCase
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

    public function test_a_timed_content_is_overdue_from_its_own_peruvian_instant(): void
    {
        $this->travelTo(PeruTime::toUtc('2026-03-08 15:00:00'));

        $earlierToday = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 14:59:00'),
            'title' => 'Antes de ahora',
        ]);
        $rightNow = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 15:00:00'),
            'title' => 'Justo ahora',
        ]);
        $laterToday = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 15:01:00'),
            'title' => 'Todavía no',
        ]);

        $this->assertTrue($earlierToday->fresh()->isOverdue());
        $this->assertTrue($rightNow->fresh()->isOverdue());
        $this->assertFalse($laterToday->fresh()->isOverdue());

        $overdueIds = ContentItem::query()->overdue()->pluck('id')->all();
        $this->assertContains($earlierToday->id, $overdueIds);
        $this->assertContains($rightNow->id, $overdueIds);
        $this->assertNotContains($laterToday->id, $overdueIds);
    }

    public function test_a_date_without_a_time_becomes_overdue_only_after_its_peruvian_day_ends(): void
    {
        // A date-only content planned for 2026-03-08 is stored at Peru midnight,
        // 2026-03-08 05:00 UTC.
        $dateOnly = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 00:00:00'),
            'title' => 'Solo fecha',
        ]);
        $this->assertSame('2026-03-08 05:00:00', $dateOnly->planned_at->format('Y-m-d H:i:s'));

        // 20:00 in Peru on the 8th is already 2026-03-09 01:00 UTC. A naive UTC
        // comparison would call the row overdue; the Peruvian day has not ended.
        $this->travelTo(PeruTime::toUtc('2026-03-08 20:00:00'));
        $this->assertSame('2026-03-09', CarbonImmutable::now()->format('Y-m-d'), 'The UTC day is already the 9th.');
        $this->assertSame('2026-03-08', PeruTime::date(CarbonImmutable::now()), 'The Peruvian day is still the 8th.');
        $this->assertFalse($dateOnly->fresh()->isOverdue());
        $this->assertNotContains($dateOnly->id, ContentItem::query()->overdue()->pluck('id')->all());

        // One second before the Peruvian day ends it is still not overdue.
        $this->travelTo(PeruTime::toUtc('2026-03-08 23:59:59'));
        $this->assertFalse($dateOnly->fresh()->isOverdue());

        // The instant the Peruvian day ends (2026-03-09 00:00 Peru) it becomes
        // overdue.
        $this->travelTo(PeruTime::toUtc('2026-03-09 00:00:00'));
        $this->assertTrue($dateOnly->fresh()->isOverdue());
        $this->assertContains($dateOnly->id, ContentItem::query()->overdue()->pluck('id')->all());
    }

    public function test_published_contents_are_never_overdue_in_either_case(): void
    {
        $this->travelTo(PeruTime::toUtc('2026-03-08 15:00:00'));

        $timedPublished = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 14:00:00'),
            'status' => ContentItemStatus::Published,
        ]);
        $dateOnlyPublished = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-07 00:00:00'),
            'status' => ContentItemStatus::Published,
        ]);

        $this->assertFalse($timedPublished->fresh()->isOverdue());
        $this->assertFalse($dateOnlyPublished->fresh()->isOverdue());
        $this->assertSame(0, ContentItem::query()->overdue()->count());
    }

    public function test_the_grid_keeps_marking_timed_contents_and_spares_the_date_only_row_for_today(): void
    {
        $this->travelTo(PeruTime::toUtc('2026-03-08 20:00:00'));

        $timedOverdue = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 18:00:00'),
            'title' => 'Timed vencido',
        ]);
        $dateOnlyToday = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 00:00:00'),
            'title' => 'Solo fecha de hoy',
        ]);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('content-item-overdue-'.$timedOverdue->id, false)
            ->assertDontSee('content-item-overdue-'.$dateOnlyToday->id, false);
    }
}
