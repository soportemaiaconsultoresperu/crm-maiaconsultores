<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentAttachmentService;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T7 — the attachment HTTP surface of a content.
 *
 * Exercises what the operator does: upload an image/video, attach an external
 * link, reorder, retire and download. It pins the private storage, the project
 * isolation (404 for a non-member or a foreign project), the `update` ability
 * on mutations, and the fact that no public URL exposes a stored file.
 *
 * The per-kind size ceiling is proven with a controlled small override of
 * `config('content.attachments.*')`; the production defaults (10 MB image,
 * 200 MB video) are never weakened.
 */
class ContentAttachmentHttpTest extends TestCase
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

    public function test_valid_image_and_video_are_stored_privately_and_listed_on_the_content(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();

        $this->upload($item, 'foto.jpg', $this->jpeg())->assertRedirect();
        $this->upload($item, 'clip.mp4', $this->mp4())->assertRedirect();

        $rows = ContentAttachment::query()->where('content_item_id', $item->id)->get();
        $this->assertCount(2, $rows);

        $image = $rows->firstWhere('kind', ContentAttachment::KIND_IMAGE);
        $video = $rows->firstWhere('kind', ContentAttachment::KIND_VIDEO);

        $this->assertNotNull($image);
        $this->assertNotNull($video);
        $this->assertSame('image/jpeg', $image->mime_type);
        $this->assertSame('video/mp4', $video->mime_type);
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($image->path));
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($video->path));

        $this->get(route('content.projects.contents.show', [$this->project, $item]))
            ->assertOk()
            ->assertSee('foto.jpg')
            ->assertSee('clip.mp4')
            ->assertSee('Archivos y enlaces');
    }

    public function test_oversize_upload_is_rejected_at_the_configured_boundary(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        // Controlled small override: production keeps 10 MB / 200 MB.
        config(['content.attachments.image_max_bytes' => 1024, 'content.attachments.video_max_bytes' => 2048]);

        $item = $this->contentItem();

        // Exactly at the ceiling: accepted.
        $this->upload($item, 'borde.jpg', $this->jpeg(1024))->assertSessionHasNoErrors();

        // One byte over: refused, and nothing extra is written.
        $this->upload($item, 'excede.jpg', $this->jpeg(1025))->assertSessionHasErrors('file');

        $this->upload($item, 'excede.mp4', $this->mp4(2049))->assertSessionHasErrors('file');

        $this->assertSame(1, ContentAttachment::query()->count());
    }

    public function test_non_media_content_is_rejected_through_the_route(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();

        $spoof = UploadedFile::fake()
            ->createWithContent('foto.jpg', 'esto no es una imagen')
            ->mimeType('image/jpeg');

        $this->post(route('content.projects.contents.attachments.store', [$this->project, $item]), ['file' => $spoof])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, ContentAttachment::query()->count());
        $this->assertSame([], Storage::disk(ContentAttachmentService::DISK)->allFiles());
    }

    public function test_unsupported_extension_is_rejected_through_the_route(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();

        $this->upload($item, 'animacion.gif', $this->jpeg())->assertSessionHasErrors('file');

        $this->assertSame(0, ContentAttachment::query()->count());
    }

    public function test_link_is_classified_when_it_names_media_and_generic_otherwise(): void
    {
        $item = $this->contentItem();

        $this->post(route('content.projects.contents.attachments.link', [$this->project, $item]), [
            'url' => 'https://cdn.example.com/media/foto.jpg',
        ])->assertRedirect();

        $classified = ContentAttachment::query()->where('url', 'https://cdn.example.com/media/foto.jpg')->firstOrFail();
        $this->assertSame(ContentAttachment::KIND_IMAGE, $classified->kind);
        $this->assertNull($classified->path);

        // A YouTube watch link, a share link with query parameters and a link
        // with no file extension are ordinary attachments now.
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://drive.google.com/file/d/1AbC/view?usp=sharing',
            'https://example.com/promo',
        ] as $generic) {
            $this->post(route('content.projects.contents.attachments.link', [$this->project, $item]), ['url' => $generic])
                ->assertRedirect();

            $row = ContentAttachment::query()->where('url', $generic)->firstOrFail();
            $this->assertSame(ContentAttachment::KIND_LINK, $row->kind);
        }

        $this->assertSame(4, ContentAttachment::query()->count());
    }

    public function test_link_route_refuses_non_http_empty_and_malformed_values(): void
    {
        $item = $this->contentItem();
        $route = route('content.projects.contents.attachments.link', [$this->project, $item]);

        $this->postJson($route, ['url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->postJson($route, ['url' => 'ftp://example.com/media/foto.jpg'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->postJson($route, ['url' => ''])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->postJson($route, ['url' => 'no-es-una-url'])->assertStatus(422)->assertJsonValidationErrors('url');

        $this->assertSame(0, ContentAttachment::query()->count());
    }

    public function test_generic_link_is_labelled_as_a_link_while_classified_keeps_the_media_label(): void
    {
        $item = $this->contentItem();

        $this->post(route('content.projects.contents.attachments.link', [$this->project, $item]), [
            'url' => 'https://cdn.example.com/media/foto.jpg',
        ])->assertRedirect();

        $this->get(route('content.projects.contents.show', [$this->project, $item]))
            ->assertOk()
            ->assertSee('content-attachment-type-image', false)
            ->assertDontSee('Se abre fuera del CRM');

        $this->post(route('content.projects.contents.attachments.link', [$this->project, $item]), [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertRedirect();

        $this->get(route('content.projects.contents.show', [$this->project, $item]))
            ->assertOk()
            ->assertSee('content-attachment-type-link', false)
            ->assertSee('Se abre fuera del CRM')
            ->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }

    public function test_more_than_the_per_content_ceiling_is_refused_links_included(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        config(['content.attachments.max_per_content' => 2]);
        $item = $this->contentItem();

        ContentAttachment::factory()->count(2)->create(['content_item_id' => $item->id]);

        $this->upload($item, 'tercero.jpg', $this->jpeg())->assertSessionHasErrors('file');

        $this->postJson(route('content.projects.contents.attachments.link', [$this->project, $item]), [
            'url' => 'https://cdn.example.com/media/tercero.jpg',
        ])->assertStatus(422)->assertJsonValidationErrors('url');

        $this->assertSame(2, ContentAttachment::query()->count());
    }

    public function test_retiring_an_attachment_keeps_the_row_and_the_file(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();
        $attachment = $this->storedImage($item);

        $path = (string) $attachment->path;

        $this->post(route('content.projects.contents.attachments.retire', [$this->project, $item, $attachment]))
            ->assertRedirect();

        // The row is retired (soft deleted) but not gone, and no code path has
        // touched the file: the content can recover both when it is restored.
        $this->assertSoftDeleted('content_attachments', ['id' => $attachment->id]);
        $this->assertSame(1, ContentAttachment::withTrashed()->count());
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($path));

        $this->get(route('content.projects.contents.show', [$this->project, $item]))
            ->assertOk()
            ->assertDontSee('foto.jpg');
    }

    public function test_download_is_authorized_for_a_member_and_404_for_a_non_member_or_foreign_project(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();
        $attachment = $this->storedImage($item);

        // A member of the project downloads the private file.
        $this->get(route('content.projects.contents.attachments.download', [$this->project, $item, $attachment]))
            ->assertOk()
            ->assertDownload('foto.jpg');

        // A user who cannot reach the project gets 404 before any policy runs.
        $stranger = User::factory()->create(['is_active' => true]);
        $this->actingAs($stranger)
            ->get(route('content.projects.contents.attachments.download', [$this->project, $item, $attachment]))
            ->assertNotFound();

        // A member of another project cannot fetch this file by addressing
        // their own project: the attachment id is resolved inside it.
        $foreign = ContentProject::factory()->create();
        $foreign->members()->attach($this->manager);
        $foreignItem = ContentItem::factory()->for($foreign, 'project')->create();

        $this->actingAs($this->manager)
            ->get(route('content.projects.contents.attachments.download', [$foreign, $foreignItem, $attachment]))
            ->assertNotFound();
    }

    public function test_reordering_is_persisted(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();
        $first = $this->storedImage($item, 'uno.jpg');
        $second = $this->storedImage($item, 'dos.jpg');

        $this->assertSame(1, $first->sort);
        $this->assertSame(2, $second->sort);

        $this->post(route('content.projects.contents.attachments.reorder', [$this->project, $item, $second]), [
            'sort' => 0,
        ])->assertRedirect();

        $this->assertSame(0, $second->fresh()->sort);
    }

    public function test_no_public_url_exposes_the_stored_file(): void
    {
        // The private disk is what makes the file unreachable: private
        // visibility, no public `url` and no `serve` handler. These assertions
        // run before faking the disk, which would replace the configured root.
        $this->assertSame('private', config('filesystems.disks.docs.visibility'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.docs'));
        $this->assertFalse((bool) (config('filesystems.disks.docs.serve') ?? false));
        $this->assertStringContainsString(
            'app/private',
            str_replace('\\', '/', (string) config('filesystems.disks.docs.root')),
        );

        Storage::fake(ContentAttachmentService::DISK);
        $item = $this->contentItem();
        $attachment = $this->storedImage($item);
        $path = (string) $attachment->path;

        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($path));

        // No public route serves the private path. The only served disk is the
        // parent `local` disk at /storage, and it is private-visibility: an
        // unsigned request is refused (403 in testing, 404 in production), and
        // no signed URL is ever generated for an attachment path.
        $public = $this->get('/storage/'.$path);
        $this->assertContains($public->getStatusCode(), [403, 404]);

        $show = $this->get(route('content.projects.contents.show', [$this->project, $item]));
        $show->assertOk();
        $this->assertStringNotContainsString('/storage/'.$path, $show->getContent());
        $show->assertSee(route('content.projects.contents.attachments.download', [$this->project, $item, $attachment]));
    }

    /**
     * Review fixes S1/S2: a plain member attaches files (this test used to
     * assert 403 for a member without `content_projects.update`), while an
     * archived project refuses every attachment write but still lists them.
     */
    public function test_plain_member_attaches_and_an_archived_project_refuses_attachment_writes(): void
    {
        Storage::fake(ContentAttachmentService::DISK);
        $member = User::factory()->create(['is_active' => true]);
        $member->givePermissionTo('content_projects.view');
        $this->project->members()->attach($member);

        $item = $this->contentItem();

        $this->actingAs($member)
            ->post(route('content.projects.contents.attachments.store', [$this->project, $item]), [
                'file' => UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame(1, ContentAttachment::query()->count());

        $this->project->archive();

        $this->actingAs($member)
            ->get(route('content.projects.contents.show', [$this->project, $item]))
            ->assertOk()
            ->assertSee('Archivos y enlaces');

        $this->actingAs($member)
            ->post(route('content.projects.contents.attachments.store', [$this->project, $item]), [
                'file' => UploadedFile::fake()->createWithContent('otra.jpg', $this->jpeg()),
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('content.projects.contents.attachments.link', [$this->project, $item]), [
                'url' => 'https://cdn.example.com/media/foto.jpg',
            ])
            ->assertForbidden();

        $this->assertSame(1, ContentAttachment::query()->count());
    }

    private function contentItem(): ContentItem
    {
        return ContentItem::factory()->for($this->project, 'project')->create();
    }

    private function upload(ContentItem $item, string $name, string $content): TestResponse
    {
        return $this->post(route('content.projects.contents.attachments.store', [$this->project, $item]), [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    private function storedImage(ContentItem $item, string $name = 'foto.jpg'): ContentAttachment
    {
        $this->upload($item, $name, $this->jpeg())->assertRedirect();

        return ContentAttachment::query()->where('content_item_id', $item->id)->latest('id')->firstOrFail();
    }

    private function jpeg(int $bytes = 64): string
    {
        return "\xFF\xD8\xFF".str_repeat("\x00", max(0, $bytes - 3));
    }

    private function mp4(int $bytes = 64): string
    {
        $head = "\x00\x00\x00\x20ftypisom";

        return $head.str_repeat("\x00", max(0, $bytes - strlen($head)));
    }
}
