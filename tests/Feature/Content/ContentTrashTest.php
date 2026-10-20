<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentAttachmentService;
use App\Services\Content\ContentFieldValueService;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * T8b — the trash, restoration and the module's permanence rules.
 *
 * Pins the confirmed product decisions: a deleted content is recoverable until
 * an administrator explicitly empties the trash; the trash keeps every value
 * and every attachment file, so a restore recovers them; nothing is purged on
 * a timer; and the explicit operator purge is the only path in the module that
 * removes a file from disk.
 */
class ContentTrashTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        Storage::fake(ContentAttachmentService::DISK);

        $this->project = ContentProject::factory()->create();
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo([
            'content_projects.view',
            'content_projects.update',
            'content_projects.archive',
        ]);
        $this->project->members()->attach($this->manager);

        $this->actingAs($this->manager);
    }

    public function test_trashing_removes_a_content_from_the_grid_and_an_idea_from_the_ideas_listing_and_shows_both_in_the_trash(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Contenido desechado']);
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create(['title' => 'Idea desechada']);

        // Both are reachable before the deletion.
        $this->get(route('content.projects.contents.index', $this->project))
            ->assertOk()->assertSee('Contenido desechado');
        $this->get(route('content.projects.ideas.index', $this->project))
            ->assertOk()->assertSee('Idea desechada');

        $this->delete(route('content.projects.contents.destroy', [$this->project, $content]))
            ->assertRedirect(route('content.projects.contents.index', $this->project));
        // Review fix S18: a trashed idea returns to the ideas section.
        $this->delete(route('content.projects.contents.destroy', [$this->project, $idea]))
            ->assertRedirect(route('content.projects.ideas.index', $this->project));

        $this->assertSoftDeleted('content_items', ['id' => $content->id]);
        $this->assertSoftDeleted('content_items', ['id' => $idea->id]);

        // Gone from the grid and from the ideas listing.
        $this->get(route('content.projects.contents.index', $this->project))
            ->assertOk()->assertDontSee('Contenido desechado');
        $this->get(route('content.projects.ideas.index', $this->project))
            ->assertOk()->assertDontSee('Idea desechada');

        // Both are listed in the trash with the deletion moment and the actions.
        $this->get(route('content.projects.contents.trash', $this->project))
            ->assertOk()
            ->assertSee('Contenido desechado')
            ->assertSee('Idea desechada')
            ->assertSee('content-trash-row-'.$content->id, false)
            ->assertSee('content-trash-restore-'.$content->id, false)
            ->assertSee('Eliminar definitivamente');
    }

    public function test_a_trashed_content_can_no_longer_be_opened_or_edited_by_its_normal_editor_routes(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Oculto']);
        $content->delete();

        $this->get(route('content.projects.contents.show', [$this->project, $content]))->assertNotFound();
        $this->get(route('content.projects.contents.edit', [$this->project, $content]))->assertNotFound();
        $this->putJson(route('content.projects.contents.update', [$this->project, $content]), [
            'planned_date' => '2026-08-01',
            'planned_time' => '10:00',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Robado',
            'body' => 'x',
            'revision' => 1,
        ])->assertNotFound();

        $this->assertSoftDeleted('content_items', ['id' => $content->id]);
        $this->assertSame('Oculto', $content->fresh()->title);
    }

    public function test_restoring_returns_the_content_with_its_values_and_attachments_and_keeps_the_files_on_disk(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'texto', 'label' => 'Texto']);

        $content = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Restaurable']);
        app(ContentFieldValueService::class)->save($content, ['texto' => 'Valor guardado']);
        $attachment = app(ContentAttachmentService::class)->upload(
            $content,
            UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()),
        );

        $disk = Storage::disk(ContentAttachmentService::DISK);
        $path = (string) $attachment->path;
        $this->assertTrue($disk->exists($path));

        $this->delete(route('content.projects.contents.destroy', [$this->project, $content]))->assertRedirect();

        // No automatic purge: trashing only marks the row and keeps the file.
        $this->assertSoftDeleted('content_items', ['id' => $content->id]);
        $this->assertNotNull($content->fresh());
        $this->assertTrue($disk->exists($path));
        $this->assertSame(1, $content->attachments()->count());

        $this->post(route('content.projects.contents.restore', [$this->project, $content]))
            ->assertRedirect(route('content.projects.contents.show', [$this->project, $content]));

        $content->refresh();
        $this->assertNull($content->deleted_at);

        // Custom values and attachments survived the round trip.
        $values = app(ContentFieldValueService::class)->values($content);
        $this->assertSame('Valor guardado', $values['texto']);
        $this->assertSame(1, $content->attachments()->count());
        $this->assertSame($path, $content->attachments()->first()->path);
        $this->assertTrue($disk->exists($path));

        // And the restored row is reachable and editable again.
        $this->get(route('content.projects.contents.show', [$this->project, $content]))->assertOk()->assertSee('Restaurable');
    }

    public function test_trashing_and_restoring_preserve_the_revision_and_a_valid_save_still_increments_it(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $this->assertSame(1, $content->fresh()->revision);

        $this->delete(route('content.projects.contents.destroy', [$this->project, $content]))->assertRedirect();
        $this->post(route('content.projects.contents.restore', [$this->project, $content]))->assertRedirect();

        $content->refresh();
        $this->assertSame(1, $content->revision);

        $this->put(route('content.projects.contents.update', [$this->project, $content]), [
            'planned_date' => '2026-08-01',
            'planned_time' => '10:00',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Editado tras restaurar',
            'body' => 'Cuerpo',
            'revision' => 1,
        ])->assertRedirect();

        $this->assertSame(2, $content->fresh()->revision);
    }

    public function test_purging_removes_the_content_its_values_its_pivots_and_every_attachment_file(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'texto', 'label' => 'Texto']);

        $objective = ContentObjective::factory()->for($this->project, 'project')->create();
        $format = ContentFormat::factory()->for($this->project, 'project')->create();
        $channel = ContentChannel::factory()->for($this->project, 'project')->create();

        $content = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Para purgar']);
        app(ContentFieldValueService::class)->save($content, ['texto' => 'Se va']);
        $content->objectives()->attach($objective);
        $content->formats()->attach($format);
        $content->channels()->attach($channel);

        $active = app(ContentAttachmentService::class)->upload(
            $content,
            UploadedFile::fake()->createWithContent('activo.jpg', $this->jpeg()),
        );
        $retired = app(ContentAttachmentService::class)->upload(
            $content,
            UploadedFile::fake()->createWithContent('retirado.png', $this->png()),
        );
        $retired->retire();

        $disk = Storage::disk(ContentAttachmentService::DISK);
        $activePath = (string) $active->path;
        $retiredPath = (string) $retired->path;
        $this->assertTrue($disk->exists($activePath));
        $this->assertTrue($disk->exists($retiredPath));

        $this->delete(route('content.projects.contents.destroy', [$this->project, $content]))->assertRedirect();

        // Trashing still keeps both files, retired one included.
        $this->assertTrue($disk->exists($activePath));
        $this->assertTrue($disk->exists($retiredPath));

        $this->delete(route('content.projects.contents.purge', [$this->project, $content]))
            ->assertRedirect(route('content.projects.contents.trash', $this->project));

        // The only observed file removal follows this explicit purge.
        $this->assertFalse($disk->exists($activePath));
        $this->assertFalse($disk->exists($retiredPath));

        $this->assertDatabaseMissing('content_items', ['id' => $content->id]);
        $this->assertDatabaseMissing('content_field_values', ['content_item_id' => $content->id]);
        $this->assertDatabaseMissing('content_item_objective', ['content_item_id' => $content->id]);
        $this->assertDatabaseMissing('content_item_format', ['content_item_id' => $content->id]);
        $this->assertDatabaseMissing('content_item_channel', ['content_item_id' => $content->id]);
        $this->assertDatabaseMissing('content_attachments', ['content_item_id' => $content->id]);
    }

    public function test_purging_requires_the_trash_ability_and_an_unauthorized_actor_removes_nothing(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $attachment = app(ContentAttachmentService::class)->upload(
            $content,
            UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()),
        );
        $path = (string) $attachment->path;
        $content->delete();

        // Review fix S1: members may purge, so the unauthorized actor is a
        // cross-project reader who reaches the project without being a member.
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view_all');
        $this->actingAs($reader);

        $this->delete(route('content.projects.contents.purge', [$this->project, $content]))->assertForbidden();
        $this->delete(route('content.projects.contents.trash.empty', $this->project))->assertForbidden();

        // Review fix S2: on an archived project even a member is refused.
        $this->project->archive();
        $this->actingAs($this->manager)
            ->delete(route('content.projects.contents.purge', [$this->project, $content]))->assertForbidden();

        $this->assertSoftDeleted('content_items', ['id' => $content->id]);
        $this->assertNotNull($content->attachments()->withTrashed()->find($attachment->id));
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($path));
    }

    public function test_purging_a_content_that_is_not_deleted_is_refused(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();

        $this->deleteJson(route('content.projects.contents.purge', [$this->project, $content]))
            ->assertStatus(422);

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'deleted_at' => null]);
    }

    public function test_purging_a_stale_trashed_model_refuses_when_the_row_was_restored_meanwhile(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $attachment = app(ContentAttachmentService::class)->upload(
            $content,
            UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()),
        );
        $path = (string) $attachment->path;
        $content->delete();

        // Someone restores it while this request still holds the trashed model.
        $stale = ContentItem::query()->withTrashed()->findOrFail($content->id);
        ContentItem::query()->withTrashed()->findOrFail($content->id)->restore();

        try {
            app(\App\Services\Content\ContentTrashService::class)->purge($stale);
            $this->fail('A restored row must not be purged.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('purge', $exception->errors());
        }

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'deleted_at' => null]);
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($path));
    }

    public function test_restoring_a_content_that_is_not_deleted_is_refused(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();

        $this->postJson(route('content.projects.contents.restore', [$this->project, $content]))
            ->assertStatus(422);

        $this->assertDatabaseHas('content_items', ['id' => $content->id, 'deleted_at' => null]);
    }

    public function test_restoring_after_a_purge_is_impossible_because_the_row_no_longer_exists(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $content->delete();
        $this->delete(route('content.projects.contents.purge', [$this->project, $content]))->assertRedirect();

        $this->assertDatabaseMissing('content_items', ['id' => $content->id]);

        $this->postJson(route('content.projects.contents.restore', [$this->project, $content->id]))
            ->assertNotFound();
    }

    public function test_trashing_refuses_a_foreign_project_row_and_a_non_member(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignItem = ContentItem::factory()->for($foreign, 'project')->create();

        // The manager cannot reach the foreign project: 404, never a deletion.
        $this->delete(route('content.projects.contents.destroy', [$foreign, $foreignItem]))->assertNotFound();
        $this->assertDatabaseHas('content_items', ['id' => $foreignItem->id, 'deleted_at' => null]);

        // Review fix S1: a plain member may trash, so the refused actor is a
        // reachable non-member reader (cross-project view, no update).
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view_all');
        $item = ContentItem::factory()->for($this->project, 'project')->create();

        $this->actingAs($reader);
        $this->delete(route('content.projects.contents.destroy', [$this->project, $item]))->assertForbidden();
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'deleted_at' => null]);

        // Review fix S2: an archived project refuses even its members.
        $this->project->archive();
        $this->actingAs($this->manager)
            ->delete(route('content.projects.contents.destroy', [$this->project, $item]))->assertForbidden();
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'deleted_at' => null]);
    }

    /** Minimal byte sequence with a real JPEG signature. */
    private function jpeg(): string
    {
        return "\xFF\xD8\xFF".str_repeat("\x00", 64);
    }

    /** Minimal byte sequence with a real PNG signature. */
    private function png(): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64);
    }
}
