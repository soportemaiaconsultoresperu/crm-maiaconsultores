<?php

namespace App\Models;

use App\Models\Concerns\Automatable;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

    /** @use HasFactory<\Database\Factories\LeadFactory> */
    /**
     * Lead (prospect). Conversion creates a new customer and keeps this
     * record in "convertido" status (ADR-001). Duplicates are a warning,
     * never a hard block (ADR-003).
     */
    class Lead extends Model
{
    use Automatable, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
'code',
        'person_type',
        'first_name',
        'last_name',
        'company_name',
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
        'ubigeo_code',
        'sector',
        'source_id',
        'status_id',
        'interest_level',
        'owner_id',
        'entered_at',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Lead {$this->code} was {$eventName}");
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<Ubigeo, $this>
     */
    public function ubigeo(): BelongsTo
    {
        return $this->belongsTo(Ubigeo::class, 'ubigeo_code', 'code');
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function convertedCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'converted_from_lead_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'subject_id')->where('subject_type', self::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }

    /**
     * Next pending future activity for this lead (ADR-012).
     */
    public function nextAction(): ?Activity
    {
        return $this->activities()
            ->whereIn('status', ['pending', 'in_process', 'overdue'])
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')
            ->first();
    }

    /**
     * Scope to final-status leads (convertido/perdido).
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeFinal(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('is_final', true));
    }
}
