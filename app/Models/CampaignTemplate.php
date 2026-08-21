<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignTemplateFactory> */
    use HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'objective',
        'status',
        'owner_id',
        'team_id',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const OBJECTIVES = [
        'reactivation', 'nurturing', 'cross_sell', 'onboarding', 'custom',
    ];

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
        return $this->hasMany(CampaignStep::class, 'template_id')
            ->where('is_template', true)
            ->orderBy('order');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForOwner(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query;
        }
        app(\App\Services\DataScopeService::class)->appliesTo($query, $user, 'campaign_templates');
        return $query;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
