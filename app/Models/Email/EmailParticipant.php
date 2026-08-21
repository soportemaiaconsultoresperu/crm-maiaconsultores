<?php

declare(strict_types=1);

namespace App\Models\Email;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B13 — EmailParticipant.
 *
 * One row per (message, kind, email) triplet. A single message can have
 * multiple recipients in different roles (to, cc, bcc) plus the sender
 * (from). The status lifecycle is enforced at the application layer via
 * the {@see KIND_*} constants (no MySQL ENUMs per C-03).
 */
class EmailParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\EmailParticipantFactory> */
    use HasFactory;

    public const KIND_TO = 'to';
    public const KIND_CC = 'cc';
    public const KIND_BCC = 'bcc';
    public const KIND_FROM = 'from';

    /**
     * The migration does not declare timestamp columns — participants are
     * append-only. Mark the model so Eloquent does not try to write
     * updated_at / created_at.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'kind',
        'email',
        'name',
    ];

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'message_id');
    }
}
