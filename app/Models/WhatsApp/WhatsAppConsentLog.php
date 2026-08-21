<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B14 — WhatsAppConsentLog.
 *
 * Append-only ledger of opt-in / opt-out events for a contact. Meta's
 * Cloud API requires explicit opt-in before any business-initiated
 * conversation and forbids any further traffic after an opt-out.
 *
 * The current consent state is mirrored on
 * {@see WhatsAppConversation::$consent_at} and
 * {@see WhatsAppConversation::$opt_out_at} for fast filtering, but this
 * log is the auditable source of truth and must never be deleted by the
 * application layer.
 *
 * Per docs/v2/01-roadmap.md §2.4 schema spec.
 */
class WhatsAppConsentLog extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppConsentLogFactory> */
    use HasFactory;

    public const TYPE_OPT_IN = 'opt_in';
    public const TYPE_OPT_OUT = 'opt_out';

    /**
     * The migration creates the table as `whatsapp_consent_log` (singular
     * noun, per the spec) and so cannot rely on Laravel's snake_case +
     * pluralize heuristic. Explicitly point the model at the canonical
     * table so `Builder` queries resolve correctly.
     *
     * @var string
     */
    protected $table = 'whatsapp_consent_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'conversation_id',
        'type',
        'source',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Contact whose consent state is being recorded.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Conversation this consent event is associated with (nullable so
     * the contact can record opt-in / opt-out before any conversation
     * exists, e.g. from the public web form flow).
     *
     * @return BelongsTo<WhatsAppConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    /**
     * Scope to opt-in events.
     *
     * @param  Builder<WhatsAppConsentLog>  $query
     */
    public function scopeOptIns(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OPT_IN);
    }

    /**
     * Scope to opt-out events.
     *
     * @param  Builder<WhatsAppConsentLog>  $query
     */
    public function scopeOptOuts(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OPT_OUT);
    }
}
