<?php

declare(strict_types=1);

namespace App\Models\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B17 — NotificationPreference.
 *
 * One row per (user, subject_type, channel) triplet describing whether a
 * given recipient wants to receive notifications about a given subject via a
 * given channel. The `subject_type` follows the V2 morph-alias convention
 * (e.g. `App\Models\Lead`, `App\Models\Opportunity`, ...).
 *
 * Per docs/v2/01-roadmap.md §2.7 and §10 (D-21a..D-21g). The `scope` column
 * distinguishes configurable commercial notifications (`optional`) from the
 * mandatory administrative / security triggers (D-21a..D-21d). D-21f
 * (new-device detection) and D-21g (SLA) are explicitly out of scope for V2.
 *
 * Scope values are validated at the application layer via the SCOPE_*
 * constants on this class (no new MySQL ENUMs per C-03).
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationPreferenceFactory> */
    use HasFactory;

    /** Configurable, opt-in commercial / product notifications. */
    public const SCOPE_OPTIONAL = 'optional';

    /** Mandatory administrative triggers (D-21a..D-21d) — cannot be opted out. */
    public const SCOPE_ADMINISTRATIVE = 'administrative';

    /** Mandatory security triggers (D-21b — account disconnect / expiry). */
    public const SCOPE_SECURITY = 'security';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'subject_type',
        'channel',
        'enabled',
        'scope',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * User that owns this preference row.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to rows that are currently enabled.
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to rows for a given delivery channel
     * (database | mail | whatsapp | webhook).
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope to rows for a given subject type (morph alias).
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeForSubject(Builder $query, string $subjectType): Builder
    {
        return $query->where('subject_type', $subjectType);
    }

    /**
     * Scope to rows for a given user id.
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to rows in the mandatory administrative scope
     * (D-21a: integration failures, D-21b: account disconnect, D-21c: cycle
     * break, D-21d: permanent retry failure).
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeAdministrative(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_ADMINISTRATIVE);
    }

    /**
     * Scope to rows in the mandatory security scope (D-21b — vendor account
     * disconnect / expiry reported to the affected user).
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeSecurity(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_SECURITY);
    }

    /**
     * Scope to rows in the optional, user-configurable scope (D-21e —
     * commercial notifications).
     *
     * @param  Builder<NotificationPreference>  $query
     */
    public function scopeOptional(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_OPTIONAL);
    }
}
