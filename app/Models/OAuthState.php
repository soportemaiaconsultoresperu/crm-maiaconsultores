<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * OAuthState — short-lived OAuth 2.0 state tokens.
 *
 * Used during the authorization-code round trip. The token in `state`
 * is opaque to the user agent; we persist it so a callback replay or a
 * stale token can be detected and refused.
 */
class OAuthState extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider',
        'state',
        'redirect_after',
        'payload_json',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Has this state token expired?
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Scope to still-valid state tokens (i.e. not past their expiry).
     *
     * @param  Builder<OAuthState>  $query
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}