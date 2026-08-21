<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B14 — WhatsAppConversation.
 *
 * One row per (account, phone_number) thread. The conversation can be
 * linked to at most one of {contact, customer, lead} — the B14 service
 * layer (Pasada B) reconciles the link on inbound traffic and on lead
 * reassignment.
 *
 * The 24-hour customer-service window is tracked explicitly via
 * {@see window_opens_at} / {@see window_closes_at} so the service layer
 * can decide whether a freeform message is allowed without re-computing
 * on every send (per docs/v2/01-roadmap.md §2.4).
 *
 * Consent state is mirrored here for fast filtering; the auditable
 * source of truth is {@see WhatsAppConsentLog}.
 *
 * @see docs/v2/01-roadmap.md §7 decisions 13a–e (lead creation rules)
 *      and 14a–c (assignment + DataScope).
 */
class WhatsAppConversation extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppConversationFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    /**
     * The migration creates the table as `whatsapp_conversations`. Explicitly
     * point the model at the canonical table so `Builder` queries resolve
     * correctly (see {@see WhatsAppAccount::$table}).
     *
     * @var string
     */
    protected $table = 'whatsapp_conversations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'contact_id',
        'customer_id',
        'lead_id',
        'phone_number',
        'contact_name',
        'status',
        'assigned_to',
        'last_message_at',
        'last_direction',
        'consent_at',
        'opt_out_at',
        'window_opens_at',
        'window_closes_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'consent_at' => 'datetime',
            'opt_out_at' => 'datetime',
            'window_opens_at' => 'datetime',
            'window_closes_at' => 'datetime',
        ];
    }

    /**
     * Vendor account this conversation is hosted on.
     *
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'account_id');
    }

    /**
     * Linked contact, if any.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Linked customer, if any.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Linked lead, if any.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * User the conversation is assigned to.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Messages exchanged on this conversation.
     *
     * @return HasMany<WhatsAppMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    /**
     * Consent log entries attached to this conversation.
     *
     * @return HasMany<WhatsAppConsentLog, $this>
     */
    public function consentLogEntries(): HasMany
    {
        return $this->hasMany(WhatsAppConsentLog::class, 'conversation_id');
    }

    /**
     * Scope to open conversations.
     *
     * @param  Builder<WhatsAppConversation>  $query
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope to closed conversations.
     *
     * @param  Builder<WhatsAppConversation>  $query
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    /**
     * Scope to conversations that have received an opt-out.
     *
     * @param  Builder<WhatsAppConversation>  $query
     */
    public function scopeOptedOut(Builder $query): Builder
    {
        return $query->whereNotNull('opt_out_at');
    }
}
