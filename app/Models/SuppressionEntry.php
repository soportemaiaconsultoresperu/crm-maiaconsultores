<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * B21 — SuppressionEntry model.
 *
 * Lista de exclusión global. Una fila = un subject que NO debe recibir envíos
 * por un canal (o NULL = global, todos los canales). Razones: opt_out, bounce,
 * complaint, manual.
 *
 * B22: `ConsentService::isEligible()` consulta `active()` para verificar
 * que NO hay suppression para (subject, channel) o (subject, NULL).
 *
 * `expires_at` = NULL → permanente. Futuro = soft-bounce con retry window.
 */
class SuppressionEntry extends Model
{
    protected $table = 'suppression_entries';

    public const REASON_OPT_OUT = 'opt_out';
    public const REASON_BOUNCE = 'bounce';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_MANUAL = 'manual';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'channel',
        'reason',
        'source',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: supresiones activas (no expiradas).
     *
     * B22 usa este scope para verificar elegibilidad antes de cada dispatch.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeForChannel(Builder $query, ?string $channel): Builder
    {
        return $query->where(function (Builder $q) use ($channel) {
            $q->whereNull('channel');
            if ($channel !== null) {
                $q->orWhere('channel', $channel);
            }
        });
    }
}
