<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignRun extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignRunFactory> */
    use HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'template_id',
        'template_hash',
        'starts_at',
        'ends_at_estimated',
        'owner_id',
        'team_id',
        'status',
        'status_changed_at',
        'status_changed_by',
        'status_reason',
        'progress_cache',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at_estimated' => 'datetime',
            'status_changed_at' => 'datetime',
            'progress_cache' => 'array',
            'template_hash' => 'string',
        ];
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALL_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_RUNNING,
        self::STATUS_PAUSED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'template_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class, 'run_id')
            ->where('is_template', false)
            ->orderBy('order');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CampaignParticipant::class, 'run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CampaignActionItem::class, 'run_id');
    }

    public function statusChangedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function scopeForOwner(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query;
        }
        app(\App\Services\DataScopeService::class)->appliesTo($query, $user, 'campaign_runs');
        return $query;
    }
}
