<?php

namespace App\Models;

use App\Models\Concerns\Automatable;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Quotation. Totals are recalculated and validated server-side.
 * Acceptance is explicit and may optionally close the opportunity
 * (ADR-007).
 */
class Quotation extends Model
{
    /** @use HasFactory<\Database\Factories\QuotationFactory> */
    use Automatable, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'lead_id',
        'customer_id',
        'contact_id',
        'opportunity_id',
        'owner_id',
        'issued_at',
        'expires_at',
        'currency_code',
        'terms',
        'observations',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'accepted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Quotation {$this->number} was {$eventName}");
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'docable_id')->where('docable_type', self::class);
    }

    /**
     * Scope to quotations not yet answered.
     *
     * @param  Builder<Quotation>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'sent']);
    }
}
