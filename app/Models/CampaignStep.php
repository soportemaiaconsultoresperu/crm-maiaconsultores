<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignStep extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignStepFactory> */
    use HasAuditColumns, HasFactory;

    protected $fillable = [
        'is_template',
        'template_id',
        'run_id',
        'source_step_id',
        'order',
        'action_type_id',
        'title',
        'day_offset',
        'scheduled_time',
        'instructions',
        'is_required',
        'is_advertising',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'is_required' => 'boolean',
            'is_advertising' => 'boolean',
            'day_offset' => 'integer',
            'order' => 'integer',
            'scheduled_time' => 'string', // HH:MM:SS
        ];
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'template_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'run_id');
    }

    public function sourceStep(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_step_id');
    }

    public function actionType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'action_type_id');
    }

    public function scopeTemplateSteps(Builder $query): Builder
    {
        return $query->where('is_template', true)
            ->whereNotNull('template_id')
            ->whereNull('run_id');
    }

    public function scopeRunSteps(Builder $query): Builder
    {
        return $query->where('is_template', false)
            ->whereNotNull('run_id')
            ->whereNull('template_id');
    }
}
