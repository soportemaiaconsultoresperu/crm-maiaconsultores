<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T8a — rescheduling a content.
 *
 * The reschedule form submits a Peru wall-clock date and time and the service
 * stores the matching UTC instant, so afterwards the content appears under the
 * correct month of the grid: the same month filter that hid it before the move
 * shows it after, and the month it left no longer does.
 */
class ContentRescheduleTest extends TestCase
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

    public function test_rescheduling_moves_the_content_into_the_other_months_filter(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-07-15 09:30:00'),
            'title' => 'Contenido a mover',
        ]);

        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'month' => '2026-07']))
            ->assertOk()->assertSee('Contenido a mover');
        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'month' => '2026-08']))
            ->assertOk()->assertDontSee('Contenido a mover');

        $this->put(route('content.projects.contents.reschedule', [$this->project, $item]), [
            'planned_date' => '2026-08-20',
            'planned_time' => '18:45',
            'revision' => $item->fresh()->revision,
        ])->assertRedirect(route('content.projects.contents.show', [$this->project, $item]));

        // Stored as the UTC instant of the Peru wall clock, not as a local date.
        $item->refresh();
        $this->assertTrue($item->planned_at->equalTo(PeruTime::toUtc('2026-08-20 18:45:00')));
        $this->assertSame(2, $item->revision);

        // The content now belongs to the new month filter and left the old one.
        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'month' => '2026-08']))
            ->assertOk()->assertSee('Contenido a mover');
        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'month' => '2026-07']))
            ->assertOk()->assertDontSee('Contenido a mover');

        // The detail screen renders the new Peru date and time.
        $this->get(route('content.projects.contents.show', [$this->project, $item]))
            ->assertOk()->assertSee('2026-08-20')->assertSee('18:45');
    }

    public function test_reschedule_validates_the_date_and_time_like_the_editor(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-07-15 09:30:00'),
        ]);

        $url = route('content.projects.contents.reschedule', [$this->project, $item]);
        $revision = $item->fresh()->revision;

        $this->put($url, ['planned_date' => '20/08/2026', 'planned_time' => '18:45', 'revision' => $revision])
            ->assertSessionHasErrors('planned_date');
        $this->put($url, ['planned_date' => '2026-08-20', 'planned_time' => '25:00', 'revision' => $revision])
            ->assertSessionHasErrors('planned_time');
        $this->put($url, ['planned_date' => '2026-08-20', 'planned_time' => '18:45', 'revision' => null])
            ->assertSessionHasErrors('revision');

        $item->refresh();
        $this->assertSame('2026-07-15', $item->plannedDate());
        $this->assertSame(1, $item->revision);
    }

    public function test_reschedule_requires_update_permission_and_a_reachable_project(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-07-15 09:30:00'),
        ]);

        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);

        $this->actingAs($reader);
        $this->put(route('content.projects.contents.reschedule', [$this->project, $item]), [
            'planned_date' => '2026-08-20',
            'planned_time' => '18:45',
            'revision' => $item->fresh()->revision,
        ])->assertForbidden();

        $foreign = ContentProject::factory()->create();
        $foreignItem = ContentItem::factory()->for($foreign, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-07-15 09:30:00'),
        ]);
        $this->actingAs($this->manager);
        $this->put(route('content.projects.contents.reschedule', [$foreign, $foreignItem]), [
            'planned_date' => '2026-08-20',
            'planned_time' => '18:45',
            'revision' => 1,
        ])->assertNotFound();

        $this->assertSame('2026-07-15', $item->fresh()->plannedDate());
        $this->assertSame('2026-07-15', $foreignItem->fresh()->plannedDate());
    }
}
