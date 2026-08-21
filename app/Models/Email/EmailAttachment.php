<?php

declare(strict_types=1);

namespace App\Models\Email;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B13 — EmailAttachment.
 *
 * One row per file attached to an email message. The actual binary lives
 * on the private disk at {@see storage_path}; {@see sha256} is the
 * integrity hash used for deduplication and tamper detection. When the
 * attachment is also tracked in the V1 {@see Document} store, the link
 * is held in {@see document_id}.
 */
class EmailAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\EmailAttachmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'document_id',
        'filename',
        'mime',
        'size',
        'storage_path',
        'sha256',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
