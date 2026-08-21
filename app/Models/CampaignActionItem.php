<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignActionItem extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignActionItemFactory> */
    use HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'run_id',
        'step_id',
        'participant_id',
        'status',
        'scheduled_at',
        'executed_at',
        'completed_by',
        'result',
        'contact_response',
        'observations',
        'cancellation_reason',
        'not_applicable_reason',
        'next_action_at',
        'next_action_notes',
        'reschedule_count',
        'last_rescheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'executed_at' => 'datetime',
            'next_action_at' => 'datetime',
            'last_rescheduled_at' => 'datetime',
            'reschedule_count' => 'integer',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROCESS = 'in_process';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const ALL_STATUSES = [
        self::STATUS_PENDING, self::STATUS_IN_PROCESS, self::STATUS_COMPLETED,
        self::STATUS_OVERDUE, self::STATUS_CANCELLED, self::STATUS_NOT_APPLICABLE,
    ];

    protected static function booted(): void
    {
        // Mutual-exclusion validation: cancellation_reason and not_applicable_reason
        // can never both be set. The SQL CHECK constraint enforces this on MySQL/
        // PostgreSQL; this boot hook makes it cross-driver (including SQLite).
        static::saving(function (self $item): void {
            $hasCancellation = $item->cancellation_reason !== null && $item->cancellation_reason !== '';
            $hasNotApplicable = $item->not_applicable_reason !== null && $item->not_applicable_reason !== '';
            if ($hasCancellation && $hasNotApplicable) {
                throw new \InvalidArgumentException(
                    'No se puede informar simultáneamente motivo de cancelación y motivo de "No aplica".'
                );
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'step_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CampaignParticipant::class, 'participant_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(CampaignItemReschedule::class, 'item_id')->orderByDesc('rescheduled_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OVERDUE);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForRun(Builder $query, CampaignRun $run): Builder
    {
        return $query->where('run_id', $run->id);
    }
}
