<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class SupportAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'previous_responsible_id',
        'new_responsible_id',
        'previous_team_id',
        'new_team_id',
        'reason',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
