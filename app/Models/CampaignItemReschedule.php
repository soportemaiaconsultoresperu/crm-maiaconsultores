<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignItemReschedule extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignItemRescheduleFactory> */
    use HasAuditColumns, HasFactory;

    protected $fillable = [
        'item_id',
        'old_scheduled_at',
        'new_scheduled_at',
        'reason',
        'rescheduled_by',
        'rescheduled_at',
        'scope',
        'preserved_individual',
    ];

    protected function casts(): array
    {
        return [
            'old_scheduled_at' => 'datetime',
            'new_scheduled_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'preserved_individual' => 'boolean',
        ];
    }

    public const SCOPE_INDIVIDUAL = 'individual';
    public const SCOPE_GLOBAL = 'global';

    public function item(): BelongsTo
    {
        return $this->belongsTo(CampaignActionItem::class, 'item_id');
    }

    public function rescheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by');
    }
}
