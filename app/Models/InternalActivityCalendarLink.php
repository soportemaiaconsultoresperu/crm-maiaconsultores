<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google Calendar event of an internal activity in one user's calendar.
 * Statuses mirror {@see ActivityCalendarLink}.
 */
class InternalActivityCalendarLink extends Model
{
    protected $fillable = [
        'internal_activity_id',
        'user_id',
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
     * @return BelongsTo<InternalActivity, $this>
     */
    public function internalActivity(): BelongsTo
    {
        return $this->belongsTo(InternalActivity::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function integrationAccount(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class);
    }
}
