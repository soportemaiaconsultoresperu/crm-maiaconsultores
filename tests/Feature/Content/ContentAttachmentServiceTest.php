<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T7 — the attachment rules, exercised at the service boundary.
 *
 * These tests pin the parts the HTTP screen alone cannot prove cheaply: the
 * exact size boundary per kind, the real-content sniff that ignores the
 * extension and the declared MIME type, the accepted-extension gate, the
 * per-content ceiling (links included), and the link inference.
 *
 * Size boundary without a 200 MB fixture: the production defaults are 10 MB for
 * images and 200 MB for videos, and they are NEVER weakened here. Each test
 * lowers `config('content.attachments.*')` to a few bytes and uploads a file of
 * exactly the configured ceiling (accepted) and one byte more (refused), which
 * proves the comparison while the defaults stay untouched.
 */
class ContentAttachmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContentAttachmentService $service;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ContentAttachmentService::DISK);
        $this->service = new ContentAttachmentService;
        $project = ContentProject::factory()->create();
        $this->item = ContentItem::factory()->for($project, 'project')->create();
    }

    public function test_image_and_video_uploads_are_stored_privately_with_detected_metadata(): void
    {
        $image = $this->upload('foto.jpg', $this->jpeg(128));

        $this->assertSame(ContentAttachment::KIND_IMAGE, $image->kind);
        $this->assertSame('image/jpeg', $image->mime_type);
        $this->assertSame('foto.jpg', $image->original_name);
        $this->assertSame(128, $image->size_bytes);
        $this->assertSame($this->item->id, $image->content_item_id);
        $this->assertNotNull($image->path);
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($image->path));

        $video = $this->upload('clip.mp4', $this->mp4(256));

        $this->assertSame(ContentAttachment::KIND_VIDEO, $video->kind);
        $this->assertSame('video/mp4', $video->mime_type);
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($video->path));

        $mov = $this->upload('movimiento.mov', $this->mov(256));

        $this->assertSame(ContentAttachment::KIND_VIDEO, $mov->kind);
        $this->assertSame('video/quicktime', $mov->mime_type);
        $this->assertTrue(Storage::disk(ContentAttachmentService::DISK)->exists($mov->path));

        // The stored names are generated, never the client name: the private
        // path cannot be guessed from the original file name.
        $this->assertStringNotContainsString('foto.jpg', (string) $image->path);
    }

    public function test_content_of_the_wrong_kind_is_rejected_even_with_an_accepted_extension(): void
    {
        // Both are accepted formats, but the sniffed kind must match the kind
        // implied by the extension: an image body cannot masquerade as a video
        // and a video body cannot masquerade as an image.
        $this->assertRejected(fn () => $this->upload('clip.mp4', $this->jpeg(64)), 'file');
        $this->assertRejected(fn () => $this->upload('foto.png', $this->mp4(64)), 'file');

        $this->assertSame(0, ContentAttachment::query()->count());
    }

    public function test_size_boundary_is_enforced_against_the_configured_limits(): void
    {
        // Controlled small override of the configured ceilings. Production keeps
        // DEFAULT_IMAGE_MAX_BYTES (10 MB) and DEFAULT_VIDEO_MAX_BYTES (200 MB).
        config([
            'content.attachments.image_max_bytes' => 1024,
            'content.attachments.video_max_bytes' => 2048,
        ]);

        // Exactly at the image ceiling: accepted.
        $atImageCeiling = $this->upload('borde.jpg', $this->jpeg(1024));
        $this->assertSame(1024, $atImageCeiling->size_bytes);

        // One byte over: refused, and nothing extra is written.
        $this->assertRejected(fn () => $this->upload('excede.jpg', $this->jpeg(1025)), 'file');
        $this->assertSame(1, ContentAttachment::query()->count());

        // The video ceiling is proven the same way, so a 200 MB fixture is not
        // needed and the production rule is not weakened.
        $atVideoCeiling = $this->upload('borde.mp4', $this->mp4(2048));
        $this->assertSame(2048, $atVideoCeiling->size_bytes);

        $this->assertRejected(fn () => $this->upload('excede.mp4', $this->mp4(2049)), 'file');
        $this->assertSame(2, ContentAttachment::query()->count());
    }

    public function test_spoofed_content_is_rejected_despite_an_accepted_name_and_declared_type(): void
    {
        // The name claims a JPG and the client-declared MIME claims image/jpeg,
        // but the real bytes are plain text, so the sniff refuses it.
        $spoof = UploadedFile::fake()
            ->createWithContent('foto.jpg', 'esto no es una imagen ni un video')
            ->mimeType('image/jpeg');

        $this->assertRejected(fn () => $this->service->upload($this->item, $spoof), 'file');

        $this->assertSame(0, ContentAttachment::query()->count());
        $this->assertSame([], Storage::disk(ContentAttachmentService::DISK)->allFiles());
    }

    public function test_unsupported_extension_is_rejected_even_when_the_bytes_are_an_accepted_image(): void
    {
        // A GIF is not in the accepted extension vocabulary even though its
        // bytes could be sniffed as an image format.
        $this->assertRejected(fn () => $this->upload('animacion.gif', $this->jpeg(64)), 'file');

        $this->assertSame(0, ContentAttachment::query()->count());
    }

    public function test_per_content_ceiling_counts_links(): void
    {
        config(['content.attachments.max_per_content' => 3]);

        $this->upload('uno.jpg', $this->jpeg());
        $this->service->attachLink($this->item, 'https://cdn.example.com/dos.jpg');
        $this->upload('tres.jpg', $this->jpeg());

        $this->assertSame(3, ContentAttachment::query()->count());

        // The fourth attachment is refused whether it is an upload or a link:
        // links count toward the same ceiling.
        $this->assertRejected(fn () => $this->upload('cuatro.jpg', $this->jpeg()), 'file');
        $this->assertRejected(
            fn () => $this->service->attachLink($this->item, 'https://cdn.example.com/cuatro.jpg'),
            'url',
        );

        $this->assertSame(3, ContentAttachment::query()->count());
    }

    public function test_link_is_inferred_as_media_and_rejects_non_media_urls(): void
    {
        $image = $this->service->attachLink($this->item, 'https://cdn.example.com/media/foto.PNG?token=abc');

        $this->assertSame(ContentAttachment::KIND_IMAGE, $image->kind);
        $this->assertSame('image/png', $image->mime_type);
        $this->assertSame('https://cdn.example.com/media/foto.PNG?token=abc', $image->url);
        $this->assertSame('foto.PNG', $image->original_name);
        $this->assertNull($image->path);
        $this->assertNull($image->size_bytes);

        $video = $this->service->attachLink($this->item, 'https://cdn.example.com/media/clip.mov');

        $this->assertSame(ContentAttachment::KIND_VIDEO, $video->kind);
        $this->assertSame('video/quicktime', $video->mime_type);

        // A URL that does not point to an accepted media format is refused.
        $this->assertRejected(
            fn () => $this->service->attachLink($this->item, 'https://cdn.example.com/media/documento.pdf'),
            'url',
        );

        $this->assertSame(2, ContentAttachment::query()->count());
    }

    public function test_limits_expose_the_agreed_defaults(): void
    {
        $limits = $this->service->limits();

        $this->assertSame(10 * 1024 * 1024, $limits['image_max_bytes']);
        $this->assertSame(200 * 1024 * 1024, $limits['video_max_bytes']);
        $this->assertSame(10, $limits['max_per_content']);
        $this->assertSame(['JPG', 'PNG', 'WEBP'], $limits['image_extensions']);
        $this->assertSame(['MP4', 'MOV'], $limits['video_extensions']);
    }

    /**
     * A minimal byte sequence whose leading bytes are a real JPEG signature,
     * padded to an exact length so the size boundary can be asserted precisely.
     */
    private function jpeg(int $bytes = 64): string
    {
        return "\xFF\xD8\xFF".str_repeat("\x00", max(0, $bytes - 3));
    }

    /**
     * A minimal MP4 byte sequence (`ftyp` + `isom` brand), padded to an exact
     * length.
     */
    private function mp4(int $bytes = 64): string
    {
        $head = "\x00\x00\x00\x20ftypisom";

        return $head.str_repeat("\x00", max(0, $bytes - strlen($head)));
    }

    /**
     * A minimal MOV byte sequence (`ftyp` + `qt  ` brand), padded to an exact
     * length.
     */
    private function mov(int $bytes = 64): string
    {
        $head = "\x00\x00\x00\x14ftypqt  ";

        return $head.str_repeat("\x00", max(0, $bytes - strlen($head)));
    }

    private function upload(string $name, string $content): ContentAttachment
    {
        return $this->service->upload(
            $this->item,
            UploadedFile::fake()->createWithContent($name, $content),
        );
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail('Expected the attachment to be rejected, but it was accepted.');
    }
}
