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
 * T8a — duplicating a content.
 *
 * Pins the confirmed product decision: the copy keeps the text, pillar, topic,
 * multi-select selections, typed custom values and attachments, but drops the
 * planned instant, resets the status to Incompleto and stays a content; the
 * original is never modified. Attachment bytes are copied to a NEW path, so
 * retiring or removing one file never touches the other, and a failure leaves
 * neither a half-copied content nor an orphan file.
 */
class ContentDuplicationTest extends TestCase
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
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->manager);

        $this->actingAs($this->manager);
    }

    public function test_duplication_copies_every_field_selection_custom_value_and_attachment(): void
    {
        $pillar = ContentPillar::factory()->for($this->project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $objective = ContentObjective::factory()->for($this->project, 'project')->create();
        $format = ContentFormat::factory()->for($this->project, 'project')->create();
        $channel = ContentChannel::factory()->for($this->project, 'project')->create();

        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)->create(['key' => 'texto', 'label' => 'Texto']);
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Date)->create(['key' => 'fecha', 'label' => 'Fecha']);
        $single = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::SingleSelection)->create(['key' => 'unica', 'label' => 'Única']);
        $option = ContentFieldOption::factory()->for($single, 'definition')->create(['label' => 'Opción A']);
        $multiple = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::MultipleSelection)->create(['key' => 'multiple', 'label' => 'Múltiple']);
        $multiOptions = ContentFieldOption::factory()->count(2)->for($multiple, 'definition')->create();

        $source = ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-07-15 09:30:00'),
            'status' => ContentItemStatus::InProgress,
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'title' => 'Campaña de verano',
            'body' => 'Copy original',
            'hashtags' => '#verano #maia',
            'notes' => 'Nota interna',
        ]);
        $source->objectives()->attach($objective);
        $source->formats()->attach($format);
        $source->channels()->attach($channel);

        app(ContentFieldValueService::class)->save($source, [
            'texto' => 'Valor de texto',
            'fecha' => '2026-08-01',
            'unica' => $option->id,
            'multiple' => $multiOptions->pluck('id')->all(),
        ]);

        $attachment = app(ContentAttachmentService::class)->upload(
            $source,
            UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()),
        );

        $this->post(route('content.projects.contents.duplicate', [$this->project, $source]))
            ->assertRedirect();

        $copy = ContentItem::query()->whereKeyNot($source->getKey())->firstOrFail();
        $copy->refresh()->load(['objectives', 'formats', 'channels', 'attachments']);

        // The undated copy renders without a planned instant.
        $this->get(route('content.projects.contents.show', [$this->project, $copy]))->assertOk();

        // The copy is a content, with no planned instant and reset to Incompleto.
        $this->assertTrue($copy->isContent());
        $this->assertNull($copy->planned_at);
        $this->assertSame(ContentItemStatus::Incomplete, $copy->status);

        // Every editorial field is copied, and the copy is visibly marked.
        $this->assertSame('Campaña de verano (copia)', $copy->title);
        $this->assertSame($source->body, $copy->body);
        $this->assertSame($source->hashtags, $copy->hashtags);
        $this->assertSame($source->notes, $copy->notes);
        $this->assertSame($pillar->id, $copy->content_pillar_id);
        $this->assertSame($topic->id, $copy->content_topic_id);

        // Selections are copied.
        $this->assertSame([$objective->id], $copy->objectives->pluck('id')->all());
        $this->assertSame([$format->id], $copy->formats->pluck('id')->all());
        $this->assertSame([$channel->id], $copy->channels->pluck('id')->all());

        // Typed custom values are copied, key by key.
        $values = app(ContentFieldValueService::class)->values($copy);
        $this->assertSame('Valor de texto', $values['texto']);
        $this->assertSame('2026-08-01', $values['fecha']);
        $this->assertSame($option->id, $values['unica']);
        $this->assertEqualsCanonicalizing($multiOptions->pluck('id')->all(), $values['multiple']);

        // The attachment is copied to its own row and its own file path.
        $this->assertSame(1, $copy->attachments->count());
        $copied = $copy->attachments->first();
        $this->assertSame($attachment->kind, $copied->kind);
        $this->assertSame($attachment->original_name, $copied->original_name);
        $this->assertSame($attachment->mime_type, $copied->mime_type);
        $this->assertNotSame($attachment->path, $copied->path);

        // The original is untouched.
        $source->refresh();
        $this->assertSame('Campaña de verano', $source->title);
        $this->assertSame(ContentItemStatus::InProgress, $source->status);
        $this->assertNotNull($source->planned_at);
        $this->assertSame(1, $source->attachments()->count());
        $this->assertSame($attachment->path, $source->attachments()->first()->path);
        $this->assertSame(1, $source->revision);
    }

    public function test_duplicated_attachment_files_are_independent_of_the_original(): void
    {
        $source = ContentItem::factory()->for($this->project, 'project')->create();
        $sourceAttachment = app(ContentAttachmentService::class)->upload(
            $source,
            UploadedFile::fake()->createWithContent('foto.png', $this->png()),
        );

        $disk = Storage::disk(ContentAttachmentService::DISK);
        $this->assertTrue($disk->exists($sourceAttachment->path));

        $this->post(route('content.projects.contents.duplicate', [$this->project, $source]))
            ->assertRedirect();

        $copy = ContentItem::query()->whereKeyNot($source->getKey())->firstOrFail();
        $copyAttachment = $copy->attachments()->firstOrFail();

        // Two distinct files, both present, with identical bytes.
        $this->assertNotSame($sourceAttachment->path, $copyAttachment->path);
        $this->assertTrue($disk->exists($sourceAttachment->path));
        $this->assertTrue($disk->exists($copyAttachment->path));
        $this->assertSame(
            $disk->get($sourceAttachment->path),
            $disk->get($copyAttachment->path),
        );

        // Retiring the original attachment (the trash behaviour) leaves the
        // copy's row and file intact.
        $this->post(route('content.projects.contents.attachments.retire', [
            $this->project,
            $source,
            $sourceAttachment,
        ]))->assertRedirect();

        $this->assertSoftDeleted('content_attachments', ['id' => $sourceAttachment->id]);
        $this->assertNotNull($copyAttachment->fresh());
        $this->assertTrue($disk->exists($copyAttachment->path));

        // Removing the original's file from disk leaves the copy's file alive:
        // the paths are truly independent, not shared.
        $disk->delete($sourceAttachment->path);
        $this->assertFalse($disk->exists($sourceAttachment->path));
        $this->assertTrue($disk->exists($copyAttachment->path));
        $this->assertNotNull($copy->attachments()->find($copyAttachment->id));
    }

    public function test_failed_duplication_leaves_no_partial_copy_and_no_orphan_file(): void
    {
        $source = ContentItem::factory()->for($this->project, 'project')->create();
        $service = app(ContentAttachmentService::class);
        $first = $service->upload($source, UploadedFile::fake()->createWithContent('uno.jpg', $this->jpeg()));
        $second = $service->upload($source, UploadedFile::fake()->createWithContent('dos.jpg', $this->jpeg()));

        // The second source file is gone: the duplication copies the first and
        // then fails on the second, so the transaction must roll back and the
        // already-copied first file must be cleaned up.
        $disk = Storage::disk(ContentAttachmentService::DISK);
        $disk->delete($second->path);

        try {
            app(ContentItemService::class)->duplicate($source);
            $this->fail('Expected the duplication to fail when an attachment file is missing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        // No half-copied content and no extra attachment rows.
        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(2, ContentAttachment::query()->count());
        $this->assertSame([$first->path], $disk->allFiles());
    }

    public function test_duplication_requires_update_permission_and_a_reachable_project(): void
    {
        $source = ContentItem::factory()->for($this->project, 'project')->create();

        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);

        $this->actingAs($reader);
        $this->post(route('content.projects.contents.duplicate', [$this->project, $source]))
            ->assertForbidden();
        $this->assertSame(1, ContentItem::query()->count());

        // A reachable project the actor cannot reach answers 404, never a copy.
        $foreign = ContentProject::factory()->create();
        $foreignItem = ContentItem::factory()->for($foreign, 'project')->create();
        $this->actingAs($this->manager);
        $this->post(route('content.projects.contents.duplicate', [$foreign, $foreignItem]))
            ->assertNotFound();

        $this->assertSame(2, ContentItem::query()->count());
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
