<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * IntegrationAccount — one row per external integration account
 * (SMTP, Gmail, Outlook, WhatsApp, Google Calendar, Outlook Calendar).
 *
 * Per docs/v2/01-roadmap.md §2.2. Per C-04, `credentials_encrypted`
 * carries secrets; `config_json` carries non-secret configuration.
 *
 * Casts:
 *   - credentials_encrypted -> encrypted (Laravel APP_KEY cipher)
 *   - config_json           -> array     (json column decoded)
 *   - scopes                -> array     (json column decoded)
 *   - booleans              -> bool
 *   - timestamps            -> datetime
 */
class IntegrationAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (IntegrationAccount $account): void {
            $account->google_active_owner_id = $account->provider === 'google'
                && $account->is_active
                && $account->owner_id !== null
                && $account->deleted_at === null
                    ? (int) $account->owner_id
                    : null;
        });

        static::deleting(function (IntegrationAccount $account): void {
            if (! $account->isForceDeleting() && $account->google_active_owner_id !== null) {
                $account->forceFill(['google_active_owner_id' => null])->saveQuietly();
            }
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'label',
        'owner_id',
        'google_active_owner_id',
        'is_shared',
        'team_id',
        'is_active',
        'test_mode',
        'config_json',
        'credentials_encrypted',
        'scopes',
        'last_synced_at',
        'last_refresh_at',
        'expires_at',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'is_active' => 'boolean',
            'test_mode' => 'boolean',
            'config_json' => 'array',
            'credentials_encrypted' => 'encrypted:array',
            'scopes' => 'array',
            'last_synced_at' => 'datetime',
            'last_refresh_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Owner of the account (NULL for shared accounts).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Team the account belongs to (NULL for personal accounts).
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * Scope to accounts that are active and not soft-deleted.
     *
     * @param  Builder<IntegrationAccount>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to accounts in test_mode. Adapters honour this flag by
     * never issuing a real outbound HTTP call.
     *
     * @param  Builder<IntegrationAccount>  $query
     */
    public function scopeTestMode(Builder $query): Builder
    {
        return $query->where('test_mode', true);
    }

    /**
     * Mark the account as just-synced. Convenience helper used by the
     * adapters at the end of a successful round-trip.
     */
    public function markSynced(): self
    {
        $this->forceFill(['last_synced_at' => now()])->save();

        return $this;
    }

    /**
     * Mark the account as just-refreshed. Used after a token refresh.
     */
    public function markRefreshed(): self
    {
        $this->forceFill(['last_refresh_at' => now()])->save();

        return $this;
    }

    /**
     * Store a credentials blob through the {@see CredentialCipher} layer
     * so call sites don't have to remember the encryption details.
     *
     * Arrays are JSON-encoded by the `encrypted:array` cast on save;
     * strings are encrypted as-is (useful for legacy rows or for tokens
     * that are already serialized).
     *
     * @param  array<string, mixed>|string  $value
     */
    public function setCredentials(mixed $value): self
    {
        if ($value !== null && ! is_array($value) && ! is_string($value)) {
            throw new \InvalidArgumentException('credentials must be a string or array.');
        }

        $this->forceFill(['credentials_encrypted' => $value])->save();

        return $this;
    }
}