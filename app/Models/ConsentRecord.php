<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * B21 — ConsentRecord model.
 *
 * Un registro = un consentimiento activo (o revocado/expirado) por (subject, channel, purpose).
 * B22 consulta `active()` para verificar elegibilidad antes de cada dispatch de campaña.
 *
 * Estados:
 *   - 'active'   → el consentimiento está vigente; subject es elegible para este canal+purpose
 *   - 'revoked'  → el subject retiró el consentimiento; NO debe recibir más envíos por este canal
 *   - 'expired'  → consentimiento con `expires_at` que pasó (futuro, B21.5)
 *
 * Scopes canónicos: `active`, `revoked`, `forChannel`, `forSubject`, `forPurpose`.
 */
class ConsentRecord extends Model
{
    protected $table = 'consent_records';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'channel',
        'source',
        'evidence',
        'purpose',
        'status',
        'granted_at',
        'revoked_at',
        'revoked_reason',
        'created_by',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Morph relation: subject puede ser Contact, Customer o Lead.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: registros activos (elegibles para envío).
     *
     * B22: `ConsentService::isEligible()` consulta este scope + `SuppressionEntry::active()`.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REVOKED);
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }
}
