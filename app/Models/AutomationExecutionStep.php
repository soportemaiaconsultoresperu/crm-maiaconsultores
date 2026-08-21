<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationExecutionStep extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationExecutionStepFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'execution_id',
        'action_id',
        'status',
        'attempt',
        'response_json',
        'queued_at',
        'started_at',
        'finished_at',
        'error_class',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'response_json' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AutomationAction::class, 'action_id');
    }
}