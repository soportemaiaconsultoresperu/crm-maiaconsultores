<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationCondition extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationConditionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'group_id',
        'rule_id',
        'field',
        'operator',
        'value',
        'value_type',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AutomationConditionGroup::class, 'group_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}