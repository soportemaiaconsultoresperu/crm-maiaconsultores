<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationAction extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationActionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rule_id',
        'position',
        'type',
        'channel',
        'recipient_strategy',
        'payload_json',
        'retry_policy_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'payload_json' => 'array',
            'retry_policy_json' => 'array',
        ];
    }

    /**
     * Convenience accessor mirroring the schema column name.
     */
    public function getPayloadAttribute(): array
    {
        return (array) ($this->payload_json ?? []);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    public function executionSteps(): HasMany
    {
        return $this->hasMany(AutomationExecutionStep::class, 'action_id');
    }
}