<?php

declare(strict_types=1);

namespace App\Models\Email;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\IntegrationAccount;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B13 — EmailMessage.
 *
 * One row per email sent or received through the CRM. Outbound messages
 * originate from an {@see IntegrationAccount}; inbound messages are populated
 * by webhook listeners (B13 Pasada B). The status lifecycle is enforced by
 * the {@see STATUS_*} constants and validated at the application layer
 * (per C-03 — no new MySQL ENUMs).
 *
 * See docs/v2/01-roadmap.md §2.3 (schema) and §10.1 D-24 (SMTP transport).
 */
class EmailMessage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SEND_UNCONFIRMED = 'send_unconfirmed';
    public const STATUS_RECEIVED = 'received';

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'direction',
        'provider_message_id',
        'idempotency_key',
        'thread_id',
        'in_reply_to',
        'from_email',
        'from_name',
        'subject',
        'body_html',
        'body_text',
        'status',
        'sent_at',
        'received_at',
        'error_class',
        'error_message',
        'related_lead_id',
        'related_customer_id',
        'related_opportunity_id',
        'related_quotation_id',
        'related_contact_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'body_html' => 'array',
            'body_text' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Vendor account that originated or received the message.
     *
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'account_id');
    }

    /**
     * @return HasMany<EmailParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(EmailParticipant::class, 'message_id');
    }

    /**
     * @return HasMany<EmailAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'message_id');
    }

    /**
     * User that authored (or queued) the message.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Linked lead, if any.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'related_lead_id');
    }

    /**
     * Linked customer, if any.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'related_customer_id');
    }

    /**
     * Linked opportunity, if any.
     *
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'related_opportunity_id');
    }

    /**
     * Linked quotation, if any.
     *
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'related_quotation_id');
    }

    /**
     * Linked contact, if any.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'related_contact_id');
    }

    /**
     * Scope to outbound messages.
     *
     * @param  Builder<EmailMessage>  $query
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    /**
     * Scope to inbound messages.
     *
     * @param  Builder<EmailMessage>  $query
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * Scope to messages with the given status.
     *
     * @param  Builder<EmailMessage>  $query
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to messages that belong to a given integration account.
     *
     * @param  Builder<EmailMessage>  $query
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
