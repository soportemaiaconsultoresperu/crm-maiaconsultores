<?php

declare(strict_types=1);

namespace App\Models\Notification;

use App\Models\IntegrationAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B17 — OutboundDelivery.
 *
 * Append-only ledger of every outbound notification attempt dispatched
 * through the B17 notification subsystem. Each row documents one logical
 * dispatch with up to N retry attempts; idempotency is keyed on
 * `idempotency_key` (CHAR(64) UNIQUE) — collisions short-circuit at the
 * application layer rather than relying on a payload hash (per
 * docs/v2/01-roadmap.md §2.7: "idempotencia por operación, no por
 * payload_hash").
 *
 * Per docs/v2/01-roadmap.md §2.7 and §10 (D-21a..D-21g). Status / channel
 * values are validated at the application layer via the STATUS_* /
 * CHANNEL_* constants on this class (no new MySQL ENUMs per C-03).
 */
class OutboundDelivery extends Model
{
    /** @use HasFactory<\Database\Factories\OutboundDeliveryFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const CHANNEL_DATABASE = 'database';
    public const CHANNEL_MAIL = 'mail';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_WEBHOOK = 'webhook';

    /**
     * Maximum number of attempts before a delivery is considered
     * permanently failed and surfaces in the audit UI. Mirrors the
     * `failedPermanently` scope on this model.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'recipient_ref',
        'template_id',
        'related_entity_type',
        'related_entity_id',
        'account_id',
        'status',
        'attempts',
        'next_attempt_at',
        'last_error',
        'last_response_code',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_response_code' => 'integer',
        ];
    }

    /**
     * Vendor account used to dispatch the delivery, when applicable.
     * Nullable so direct (intra-CRM) deliveries work without a vendor.
     *
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'account_id');
    }

    /**
     * Scope to deliveries still waiting in the queue.
     *
     * @param  Builder<OutboundDelivery>  $query
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    /**
     * Scope to deliveries in a given status.
     *
     * @param  Builder<OutboundDelivery>  $query
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to deliveries over a given channel
     * (database | mail | whatsapp | webhook).
     *
     * @param  Builder<OutboundDelivery>  $query
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope to deliveries that exhausted the retry budget and are now
     * permanently failed (status='failed' AND attempts >= MAX_ATTEMPTS).
     *
     * @param  Builder<OutboundDelivery>  $query
     */
    public function scopeFailedPermanently(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_FAILED)
            ->where('attempts', '>=', self::MAX_ATTEMPTS);
    }

    /**
     * Scope to deliveries linked to a given domain entity via the
     * polymorphic (related_entity_type, related_entity_id) pair.
     *
     * @param  Builder<OutboundDelivery>  $query
     */
    public function scopeForEntity(Builder $query, string $type, int $id): Builder
    {
        return $query
            ->where('related_entity_type', $type)
            ->where('related_entity_id', $id);
    }
}
