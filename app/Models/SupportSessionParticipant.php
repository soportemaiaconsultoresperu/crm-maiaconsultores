<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportSessionParticipant extends Model
{
    protected $fillable = ['name', 'email', 'attended'];

    protected function casts(): array
    {
        return ['attended' => 'boolean'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SupportSessionDetail::class, 'support_session_detail_id');
    }
}
