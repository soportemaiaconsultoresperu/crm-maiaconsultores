<?php

namespace App\Models;

use App\Models\Concerns\Automatable;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sales opportunity. Exactly one of lead_id/customer_id must be set
 * (enforced by FormRequest + service; MySQL cannot cross-table CHECK).
 * No "next action" columns: activities are the single source (ADR-012).
 */
class Opportunity extends Model
{
    use Automatable, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'title',
        'lead_id',
        'customer_id',
        'contact_id',
        'owner_id',
        'stage_id',
        'estimated_amount',
        'currency_code',
        'probability',
        'expected_close_at',
        'source_id',
        'priority',
        'description',
        'loss_reason_id',
        'closed_at',
        'final_amount',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'probability' => 'decimal:2',
            'expected_close_at' => 'date',
            'closed_at' => 'datetime',
            'final_amount' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Opportunity {$this->code} was {$eventName}");
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<LossReason, $this>
     */
    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class, 'loss_reason_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<OpportunityStageHistory, $this>
     */
    public function stageHistories(): HasMany
    {
        return $this->hasMany(OpportunityStageHistory::class);
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
     * Next pending future activity for this opportunity (ADR-012).
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
     * Scope to open opportunities (stage_type = open).
     *
     * @param  Builder<Opportunity>  $query
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'open'));
    }
}
