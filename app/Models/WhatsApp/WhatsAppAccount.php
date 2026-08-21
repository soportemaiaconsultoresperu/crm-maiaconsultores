<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\IntegrationAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B14 — WhatsAppAccount.
 *
 * One row per WhatsApp phone number registered in the Meta Cloud API.
 * The vendor-side credentials (access token, business id, phone_number_id)
 * live on the linked {@see IntegrationAccount} — this row carries the
 * CRM-side metadata that drives the inbox and reporting.
 *
 * Per docs/v2/01-roadmap.md §2.4. Status values are constrained at the
 * application layer via the STATUS_* constants (no new MySQL ENUMs per
 * C-03).
 *
 * @see docs/v2/01-roadmap.md §7 decisions 12a/12b (Meta Cloud API + contract swap-ready).
 */
class WhatsAppAccount extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppAccountFactory> */
    use HasFactory;

    /** Account verified by Meta and ready to send / receive. */
    public const STATUS_VERIFIED = 'verified';

    /** Account registered but not yet verified by Meta. */
    public const STATUS_PENDING = 'pending';

    /** Account disabled by the operator. */
    public const STATUS_DISABLED = 'disabled';

    /** Account flagged by Meta as restricted (quality / policy). */
    public const STATUS_RESTRICTED = 'restricted';

    /**
     * The migration creates the table as `whatsapp_accounts` (without the
     * underscore that Laravel's default snake_case + pluralize would produce
     * for the class name `WhatsAppAccount`). Explicitly point the model at
     * the canonical table so `Builder` queries resolve correctly.
     *
     * @var string
     */
    protected $table = 'whatsapp_accounts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'phone_number_id',
        'business_id',
        'phone_number',
        'display_name',
        'status',
        'verified_at',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * Vendor account (secrets + token) in the shared B11 table.
     *
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function integrationAccount(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'account_id');
    }

    /**
     * Templates synced from Meta for this phone number.
     *
     * @return HasMany<WhatsAppTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'account_id');
    }

    /**
     * Conversations (threads) hosted on this phone number.
     *
     * @return HasMany<WhatsAppConversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'account_id');
    }

    /**
     * Scope to accounts that are not disabled.
     *
     * @param  Builder<WhatsAppAccount>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_DISABLED);
    }
}
