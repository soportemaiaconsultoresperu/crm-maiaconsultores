<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    use HasAuditColumns;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'stage_type',
        'default_probability',
        'sort',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stage_type' => 'string',
            'default_probability' => 'decimal:2',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'stage_id');
    }

    /**
     * Scope to stages of the given type (open, won, lost).
     *
     * @param  Builder<PipelineStage>  $query
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('stage_type', $type);
    }
}
