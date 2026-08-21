<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationCycleBreak extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationCycleBreakFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rule_id',
        'subject_type',
        'subject_id',
        'reason',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}