<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T8a — optimistic concurrency on the content editor.
 *
 * The edit form carries the `revision` it loaded and a successful save
 * increments it. A save whose revision is no longer current is refused with a
 * Spanish message and overwrites nothing, so two sequential edits by different
 * users behave as the confirmed decision requires: the later editor is warned
 * instead of silently erasing the first editor's work.
 */
class ContentConcurrencyTest extends TestCase
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
     * A complete update payload that carries the revision the caller loaded.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(ContentItem $item, array $overrides = []): array
    {
        $item->refresh();

        return array_merge([
            'planned_date' => $item->plannedDate() ?? '2026-07-15',
            'planned_time' => $item->planned_at ? PeruTime::toPeru($item->planned_at)->format('H:i') : '09:30',
            'status' => $item->status->value,
            'title' => $item->title,
            'body' => $item->body,
            'revision' => $item->revision,
        ], $overrides);
    }

    public function test_edit_form_carries_the_current_revision(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();

        $this->get(route('content.projects.contents.edit', [$this->project, $item]))
            ->assertOk()
            ->assertSee('name="revision" value="1"', false);
    }

    public function test_save_with_the_current_revision_succeeds_and_increments_it(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Original']);

        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
            'title' => 'Guardado',
        ]))->assertRedirect(route('content.projects.contents.show', [$this->project, $item]));

        $item->refresh();
        $this->assertSame('Guardado', $item->title);
        $this->assertSame(2, $item->revision);

        // The next edit screen shows the incremented revision.
        $this->get(route('content.projects.contents.edit', [$this->project, $item]))
            ->assertOk()
            ->assertSee('name="revision" value="2"', false);
    }

    public function test_save_with_a_stale_revision_is_refused_and_overwrites_nothing(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Original']);

        // A first writer saves, moving the row to revision 2.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
            'title' => 'Primero',
        ]))->assertRedirect();

        // A second writer still holds revision 1: the save must be refused.
        $response = $this->from(route('content.projects.contents.edit', [$this->project, $item]))
            ->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
                'title' => 'Segundo',
                'revision' => 1,
            ]));

        $response->assertRedirect(route('content.projects.contents.edit', [$this->project, $item]));
        $response->assertSessionHasErrors([
            'revision' => 'El contenido cambió desde que lo abrió. No se guardaron sus cambios para no sobrescribir el trabajo de otra persona. Vuelva a abrirlo para ver la versión más reciente.',
        ]);

        // Nothing was overwritten and the revision did not move.
        $item->refresh();
        $this->assertSame('Primero', $item->title);
        $this->assertSame(2, $item->revision);
    }

    public function test_save_without_the_revision_token_is_refused_instead_of_last_write_wins(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Original']);

        $payload = $this->payload($item, ['title' => 'Sin token']);
        unset($payload['revision']);

        $this->putJson(route('content.projects.contents.update', [$this->project, $item]), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revision');

        $this->assertSame('Original', $item->fresh()->title);
        $this->assertSame(1, $item->fresh()->revision);
    }

    public function test_two_sequential_edits_by_different_users_behave_as_the_rule_requires(): void
    {
        $userA = $this->manager;
        $userB = User::factory()->create(['is_active' => true]);
        $userB->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($userB);

        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Original']);

        // User A opens the editor and sees revision 1.
        $this->actingAs($userA);
        $this->get(route('content.projects.contents.edit', [$this->project, $item]))
            ->assertOk()
            ->assertSee('name="revision" value="1"', false);

        // User B saves first, moving the row to revision 2.
        $this->actingAs($userB);
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
            'title' => 'Edición de B',
        ]))->assertRedirect();
        $this->assertSame(2, $item->fresh()->revision);

        // User A saves from the still-open screen (revision 1): refused, and
        // B's work is preserved.
        $this->actingAs($userA);
        $this->from(route('content.projects.contents.edit', [$this->project, $item]))
            ->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
                'title' => 'Edición de A (vieja)',
                'revision' => 1,
            ]))
            ->assertSessionHasErrors('revision');

        $item->refresh();
        $this->assertSame('Edición de B', $item->title);
        $this->assertSame(2, $item->revision);

        // A reopens the screen, now sees revision 2, and this save is accepted.
        $this->get(route('content.projects.contents.edit', [$this->project, $item]))
            ->assertOk()
            ->assertSee('name="revision" value="2"', false);

        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
            'title' => 'Edición de A (nueva)',
            'revision' => 2,
        ]))->assertRedirect();

        $item->refresh();
        $this->assertSame('Edición de A (nueva)', $item->title);
        $this->assertSame(3, $item->revision);
    }

    public function test_reschedule_with_a_stale_revision_is_refused(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-07-15 09:30:00'),
        ]);

        // Another writer saves the content first.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
            'title' => 'Cambio ajeno',
        ]))->assertRedirect();
        $this->assertSame(2, $item->fresh()->revision);

        // A stale reschedule (revision 1) must not move the date.
        $this->put(route('content.projects.contents.reschedule', [$this->project, $item]), [
            'planned_date' => '2026-08-20',
            'planned_time' => '18:45',
            'revision' => 1,
        ])->assertSessionHasErrors('revision');

        $item->refresh();
        $this->assertSame('2026-07-15', $item->plannedDate());
        $this->assertSame(2, $item->revision);
        $this->assertSame('Cambio ajeno', $item->title);
    }

    public function test_stale_revision_does_not_touch_selections_or_custom_values(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create([
            'title' => 'Original',
            'status' => ContentItemStatus::InProgress,
        ]);

        // Winner save resets the status and title.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
            'title' => 'Ganador',
            'status' => ContentItemStatus::Incomplete->value,
        ]))->assertRedirect();

        // Loser save tries to publish and rename from a stale screen.
        $this->from(route('content.projects.contents.edit', [$this->project, $item]))
            ->put(route('content.projects.contents.update', [$this->project, $item]), $this->payload($item, [
                'title' => 'Perdedor',
                'status' => ContentItemStatus::Published->value,
                'revision' => 1,
            ]))
            ->assertSessionHasErrors('revision');

        $item->refresh();
        $this->assertSame('Ganador', $item->title);
        $this->assertSame(ContentItemStatus::Incomplete, $item->status);
        $this->assertSame(2, $item->revision);
    }
}
