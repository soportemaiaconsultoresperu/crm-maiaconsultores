<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B14 — WhatsAppMessage.
 *
 * One row per message exchanged with Meta Cloud API. Status transitions
 * follow the standard Meta lifecycle: queued → sent → delivered → read,
 * with `failed` as a terminal side-channel for retry-exhausted errors.
 *
 * `idempotency_key` is the SHA-256 hash computed by the sender so
 * automation-driven sends cannot double-fire on retry. `provider_message_id`
 * is the id Meta returns for outbound messages (or the inbound webhook id);
 * it is UNIQUE so duplicate webhooks become a constraint violation.
 *
 * Per docs/v2/01-roadmap.md §2.4 and §7 decisions 12a/15a.
 */
class WhatsAppMessage extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppMessageFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    /**
     * The migration creates the table as `whatsapp_messages`. Explicitly
     * point the model at the canonical table so `Builder` queries resolve
     * correctly (see {@see WhatsAppAccount::$table}).
     *
     * @var string
     */
    protected $table = 'whatsapp_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'provider_message_id',
        'wamid',
        'direction',
        'type',
        'body',
        'template_id',
        'status',
        'error_class',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Conversation this message belongs to.
     *
     * @return BelongsTo<WhatsAppConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    /**
     * Template used to send this message (null for freeform / inbound).
     *
     * @return BelongsTo<WhatsAppTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    /**
     * Scope to outbound messages.
     *
     * @param  Builder<WhatsAppMessage>  $query
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    /**
     * Scope to inbound messages.
     *
     * @param  Builder<WhatsAppMessage>  $query
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * Scope to messages with the given status.
     *
     * @param  Builder<WhatsAppMessage>  $query
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to messages that have been queued but not yet sent.
     *
     * @param  Builder<WhatsAppMessage>  $query
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }
}
