<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomerInvoice extends Model
{
    use HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'customer_id', 'invoice_number', 'due_date', 'total_amount', 'status_id',
        'notes', 'retired_at', 'retired_by', 'retire_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'retired_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Customer invoice {$this->invoice_number} was {$eventName}");
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(InvoiceStatus::class, 'status_id');
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeDueBetween(Builder $query, string $start, string $end): Builder
    {
        return $query->whereDate('due_date', '>=', $start)
            ->whereDate('due_date', '<=', $end);
    }

    public function scopeChargeable(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $statusQuery) => $statusQuery->whereNotIn('slug', [
            InvoiceStatus::SLUG_PAID,
            InvoiceStatus::SLUG_CREDIT_NOTE,
        ]));
    }

    public function scopeEligibleForOverdueProcessing(Builder $query, \Carbon\CarbonInterface $today): Builder
    {
        return $query
            ->active()
            ->whereDate('due_date', '<', $today->toDateString())
            ->whereHas('status', fn (Builder $statusQuery) => $statusQuery->whereNotIn('slug', InvoiceStatus::overdueExcludedSlugs()));
    }

    public function isEligibleForOverdueProcessing(\Carbon\CarbonInterface $today): bool
    {
        $statusSlug = $this->status?->slug;

        return $this->retired_at === null
            && $this->due_date !== null
            && $this->due_date->lt($today->startOfDay())
            && $statusSlug !== null
            && ! in_array($statusSlug, InvoiceStatus::overdueExcludedSlugs(), true);
    }
}
