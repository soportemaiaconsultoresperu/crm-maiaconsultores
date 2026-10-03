<?php

namespace App\Models\Content;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A media attachment of a content item (T7): an uploaded image/video stored on
 * a private disk, or an external link to an image/video.
 *
 * `kind` distinguishes the media type (image | video); an upload carries
 * `path` and a link carries `url`, never both. Uploads are only ever served
 * through the authorized download route: there is no public URL for `path`.
 *
 * Retirement is the module's soft-delete behaviour, not a physical purge:
 * `retire()` marks the row so the attachment can be restored with its content,
 * and the file stays on disk. Nothing here deletes a file.
 *
 * @use HasFactory<\Database\Factories\Content\ContentAttachmentFactory>
 */
class ContentAttachment extends Model
{
    use HasAuditColumns, HasFactory, SoftDeletes;

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'content_item_id',
        'kind',
        'path',
        'url',
        'original_name',
        'mime_type',
        'size_bytes',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Manual order first, primary key as a stable tie-breaker, so the
        // relation and the reorder screen never disagree.
        static::addGlobalScope('ordered', function (Builder $query): void {
            $query->ordered();
        });
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    /** An uploaded file: it has a private-disk path and no external URL. */
    public function isUpload(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /** An external link: it has a URL and no private-disk path. */
    public function isLink(): bool
    {
        return $this->url !== null && $this->url !== '';
    }

    /**
     * Retire the attachment without deleting the file. Soft delete is what
     * lets the content recover its attachments; the disk file is untouched.
     */
    public function retire(): self
    {
        $this->delete();

        return $this;
    }
}
