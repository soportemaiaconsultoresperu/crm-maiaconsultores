<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DemoDataBatch extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DELETING = 'deleting';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_RESETTING = 'resetting';
    public const STATUS_RESET = 'reset';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_DELETING,
        self::STATUS_DELETED,
        self::STATUS_RESETTING,
        self::STATUS_RESET,
    ];

    protected $fillable = [
        'uuid',
        'scenario_name',
        'status',
        'modules',
        'record_counts',
        'created_by',
        'started_at',
        'finished_at',
        'reset_at',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'record_counts' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reset_at' => 'datetime',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(\App\Models\DemoDataRecord::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_RESETTING, self::STATUS_DELETING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_RESETTING, self::STATUS_DELETING], true);
    }
}
