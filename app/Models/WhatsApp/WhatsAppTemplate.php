<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B14 — WhatsAppTemplate.
 *
 * Mirror of a Meta Cloud API template for a given WhatsApp account.
 * Only templates with {@see STATUS_APPROVED} are eligible for sending
 * (decision 15c). Template bodies are interpolated against the
 * {@see variables_json} allow-list — never against user-supplied code.
 *
 * Per docs/v2/01-roadmap.md §2.4 and §7 decisions 15a–d. Status values
 * are constrained at the application layer via the STATUS_* constants
 * (no new MySQL ENUMs per C-03).
 *
 * @see docs/v2/01-roadmap.md §7 decision 15c (only `approved` templates
 *      are usable for outbound).
 */
class WhatsAppTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppTemplateFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DISABLED = 'disabled';

    /**
     * The migration creates the table as `whatsapp_templates`. Explicitly
     * point the model at the canonical table so `Builder` queries resolve
     * correctly (see {@see WhatsAppAccount::$table}).
     *
     * @var string
     */
    protected $table = 'whatsapp_templates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'name',
        'language',
        'status',
        'category',
        'body',
        'header_kind',
        'header_text',
        'footer_text',
        'variables_json',
        'approved_at',
        'rejected_reason',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'variables_json' => 'array',
            'approved_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Vendor account this template belongs to.
     *
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'account_id');
    }

    /**
     * Messages sent using this template.
     *
     * @return HasMany<WhatsAppMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'template_id');
    }

    /**
     * Scope to templates that Meta has approved and are eligible for send.
     *
     * @param  Builder<WhatsAppTemplate>  $query
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
