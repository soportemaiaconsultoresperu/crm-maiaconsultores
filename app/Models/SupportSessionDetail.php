<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportSessionDetail extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class); }
    public function activity(): BelongsTo { return $this->belongsTo(Activity::class); }
    public function participants(): HasMany { return $this->hasMany(SupportSessionParticipant::class); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'docable'); }
}
