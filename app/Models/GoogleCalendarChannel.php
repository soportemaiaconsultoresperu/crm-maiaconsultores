<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarChannel extends Model
{
    use HasFactory;

    public const PROVIDER = 'google';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RENEWING = 'renewing';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_RENEWING,
        self::STATUS_EXPIRED,
        self::STATUS_STOPPED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'integration_account_id',
        'provider',
        'external_calendar_id',
        'channel_id',
        'resource_id',
        'resource_uri',
        'channel_token_hash',
        'status',
        'expires_at',
        'last_message_number',
        'last_received_at',
        'last_renewed_at',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_received_at' => 'datetime',
            'last_renewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function integrationAccount(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class);
    }

    /**
     * @param  Builder<GoogleCalendarChannel>  $query
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_RENEWING]);
    }

    public function hasToken(string $token): bool
    {
        if ($this->channel_token_hash === null || $this->channel_token_hash === '') {
            return $token === '';
        }

        return hash_equals($this->channel_token_hash, self::tokenHash($token));
    }

    public function isStaleMessage(?string $messageNumber): bool
    {
        if ($messageNumber === null || $messageNumber === '') {
            return false;
        }

        if ($this->last_message_number === null || $this->last_message_number === '') {
            return false;
        }

        return self::compareMessageNumbers($messageNumber, $this->last_message_number) <= 0;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function compareMessageNumbers(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
