<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationExecution extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationExecutionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rule_id',
        'trigger_event',
        'subject_type',
        'subject_id',
        'idempotency_key',
        'status',
        'attempt',
        'started_at',
        'finished_at',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationExecutionStep::class, 'execution_id')->orderBy('id');
    }
}