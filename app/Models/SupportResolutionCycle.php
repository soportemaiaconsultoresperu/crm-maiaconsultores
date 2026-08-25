<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportResolutionCycle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(\App\Models\SupportStatusPeriod::class, 'cycle_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }
}
