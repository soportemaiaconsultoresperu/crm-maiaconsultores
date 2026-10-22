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
 * Every content carries an explicit Peru time (the forms require one), so a
 * content is overdue from its own Peruvian instant, including one planned for
 * 00:00. Review fix S20 dropped the former date-only grace, which kept a row
 * stored at Peru midnight "on time" until its day ended: no form can create a
 * date-only row, so the grace only ever hid an explicit 00:00 publication. The
 * tests also prove published rows are never overdue and that the grid marker
 * follows the same rule.
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

    public function test_an_explicit_midnight_content_is_overdue_right_after_that_peruvian_instant(): void
    {
        // A content planned for 2026-03-08 00:00 in Peru is stored at
        // 2026-03-08 05:00 UTC.
        $midnight = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 00:00:00'),
            'title' => 'Medianoche',
        ]);
        $this->assertSame('2026-03-08 05:00:00', $midnight->planned_at->format('Y-m-d H:i:s'));

        // One second before, in Peru, it is still on time, even though the UTC
        // day is already the 8th.
        $this->travelTo(PeruTime::toUtc('2026-03-07 23:59:59'));
        $this->assertSame('2026-03-08', CarbonImmutable::now()->format('Y-m-d'), 'The UTC day is already the 8th.');
        $this->assertFalse($midnight->fresh()->isOverdue());
        $this->assertNotContains($midnight->id, ContentItem::query()->overdue()->pluck('id')->all());

        // From that Peruvian instant on it is overdue, with no grace for the
        // rest of the day.
        $this->travelTo(PeruTime::toUtc('2026-03-08 00:00:00'));
        $this->assertTrue($midnight->fresh()->isOverdue());
        $this->assertContains($midnight->id, ContentItem::query()->overdue()->pluck('id')->all());

        $this->travelTo(PeruTime::toUtc('2026-03-08 00:01:00'));
        $this->assertTrue($midnight->fresh()->isOverdue());
        $this->assertContains($midnight->id, ContentItem::query()->overdue()->pluck('id')->all());
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

    public function test_the_grid_marks_every_reached_instant_including_todays_midnight(): void
    {
        $this->travelTo(PeruTime::toUtc('2026-03-08 20:00:00'));

        $timedOverdue = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 18:00:00'),
            'title' => 'Timed atrasado',
        ]);
        $midnightToday = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 00:00:00'),
            'title' => 'Medianoche de hoy',
        ]);
        $later = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 21:00:00'),
            'title' => 'Más tarde',
        ]);

        $this->get(route('content.projects.contents.index', $this->project))->assertOk()
            ->assertSee('content-item-overdue-'.$timedOverdue->id, false)
            ->assertSee('content-item-overdue-'.$midnightToday->id, false)
            ->assertDontSee('content-item-overdue-'.$later->id, false);
    }
}
