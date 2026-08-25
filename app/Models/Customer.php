<?php

namespace App\Models;

use App\Models\Concerns\Automatable;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Customer. Created through lead conversion (ADR-001) or directly.
 */
class Customer extends Model
{
    use Automatable, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    /** @use HasFactory<\Database\Factories\CustomerFactory> */

    /**
     * @var list<string>
     */
protected $fillable = [
        'code',
        'person_type',
        'first_name',
        'last_name',
        'legal_name',
        'trade_name',
        'position',
        'doc_type',
        'doc_number',
        'doc_number_norm',
        'phone',
        'phone_norm',
        'whatsapp',
        'whatsapp_norm',
        'email',
        'email_norm',
        'website',
        'address',
        'fiscal_address',
        'ubigeo_code',
        'sector',
        'owner_id',
        'status',
        'converted_from_lead_id',
        'converted_at',
        'observations',
        'payment_modality',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Customer {$this->code} was {$eventName}");
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Ubigeo, $this>
     */
    public function ubigeo(): BelongsTo
    {
        return $this->belongsTo(Ubigeo::class, 'ubigeo_code', 'code');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function convertedFromLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'converted_from_lead_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * The single active primary contact (uniqueness guaranteed
     * transactionally by the contact service).
     */
    public function primaryContact(): ?Contact
    {
        return $this->contacts()->where('is_primary', true)->where('is_active', true)->first();
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'subject_id')->where('subject_type', self::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }

    /**
     * Catalog products associated with this customer (what they buy, own,
     * or are interested in). Many-to-many through `customer_product`.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'customer_product')
            ->withPivot('notes', 'quantity', 'price_override', 'purchased_at', 'expires_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<CustomerInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    /**
     * Scope to active customers.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'activo');
    }
}
