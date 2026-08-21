<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * B12 — AutomationRule.
 *
 * A rule binds a trigger event to a set of conditions and a list of
 * actions. Conditions and Actions are full rows in their own tables
 * (per C-02) — this model just exposes them through relations.
 */
class AutomationRule extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationRuleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'trigger_event',
        'is_active',
        'order',
        'mode',
        'created_by',
        'owner_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(AutomationCondition::class, 'rule_id');
    }

    public function conditionGroups(): HasMany
    {
        return $this->hasMany(AutomationConditionGroup::class, 'rule_id')->orderBy('position');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class, 'rule_id')->orderBy('position');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'rule_id');
    }

    public function cycleBreaks(): HasMany
    {
        return $this->hasMany(AutomationCycleBreak::class, 'rule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @param  Builder<AutomationRule>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<AutomationRule>  $query
     */
    public function scopeForTrigger(Builder $query, string $eventClass): Builder
    {
        return $query->where('trigger_event', $eventClass);
    }

    /**
     * @param  Builder<AutomationRule>  $query
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('id');
    }

    public function isLiveMode(): bool
    {
        return $this->mode === 'live';
    }
}