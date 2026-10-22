<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentAttachment> */
class ContentAttachmentFactory extends Factory
{
    protected $model = ContentAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItemFactory::new(),
            'kind' => ContentAttachment::KIND_IMAGE,
            'path' => 'content-attachments/'.Str::uuid()->toString().'.jpg',
            'url' => null,
            'original_name' => 'foto.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'sort' => 0,
        ];
    }

    /** An external link instead of an uploaded file. */
    public function link(string $url = 'https://cdn.example.com/media/foto.jpg'): static
    {
        return $this->state(fn (): array => [
            'path' => null,
            'url' => $url,
            'original_name' => basename((string) parse_url($url, PHP_URL_PATH)),
            'size_bytes' => null,
        ]);
    }

    /** A video upload. */
    public function video(): static
    {
        return $this->state(fn (): array => [
            'kind' => ContentAttachment::KIND_VIDEO,
            'path' => 'content-attachments/'.Str::uuid()->toString().'.mp4',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024 * 1024,
        ]);
    }
}
