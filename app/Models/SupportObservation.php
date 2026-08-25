<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportObservation extends Model
{
    use SoftDeletes;

    /** Fixed internal workflow slugs, deliberately strings rather than database ENUM. */
    public const STATES = ['pending', 'in_process', 'lifted', 'validated', 'rejected', 'reopened', 'not_applicable'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'due_at' => 'datetime', 'lifted_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(\App\Models\SupportObservationHistory::class, 'observation_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }
}
