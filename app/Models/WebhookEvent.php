<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * WebhookEvent — durable record of every inbound webhook delivery.
 *
 * Per docs/v2/01-roadmap.md §2.2. UNIQUE (provider, external_event_id)
 * is the primary defence against duplicate processing. This model is
 * read-mostly: adapters and jobs write through dedicated services,
 * never by mutating attributes directly.
 */
class WebhookEvent extends Model
{
    /** @var list<string> */
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'external_event_id',
        'payload_hash',
        'signature',
        'received_at',
        'processed_at',
        'status',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'status' => 'string',
        ];
    }

    /**
     * Scope to events that still need processing (received but not
     * yet processed or rejected).
     *
     * @param  Builder<WebhookEvent>  $query
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    /**
     * Scope to events emitted by a specific provider.
     *
     * @param  Builder<WebhookEvent>  $query
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Mark the event as successfully processed.
     */
    public function markProcessed(): self
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Mark the event as rejected (signature failure, policy refusal).
     */
    public function markRejected(string $errorClass, ?string $errorMessage = null): self
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'processed_at' => now(),
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ])->save();

        return $this;
    }

    /**
     * Mark the event as failed during processing.
     */
    public function markFailed(string $errorClass, ?string $errorMessage = null): self
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ])->save();

        return $this;
    }
}