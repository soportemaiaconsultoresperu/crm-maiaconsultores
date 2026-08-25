<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityCalendarLink extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCING = 'syncing';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_TEMPORARY_ERROR = 'temporary_error';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NOT_SYNCABLE = 'not_syncable';
    public const STATUS_EXTERNAL_EVENT_MISSING = 'external_event_missing';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SYNCING,
        self::STATUS_SYNCED,
        self::STATUS_TEMPORARY_ERROR,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_NOT_SYNCABLE,
        self::STATUS_EXTERNAL_EVENT_MISSING,
    ];

    protected $fillable = [
        'activity_id',
        'integration_account_id',
        'provider',
        'external_calendar_id',
        'external_event_id',
        'sync_hash',
        'sync_status',
        'last_synced_at',
        'last_attempt_at',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function integrationAccount(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class);
    }
}
