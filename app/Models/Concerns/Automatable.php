<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tag;
use App\Models\Taggable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Polymorphic tag support for any model that should be "automatable"
 * (rules can add tags, automation actions can target these subjects).
 *
 * Applies `tags()` (MorphToMany through taggables) and `taggables()`
 * (MorphMany on the raw pivot). No schema change required because the
 * pivot table is global.
 */
trait Automatable
{
    /**
     * Direct pivot access: AutomationRule executions / migrations can read
     * the raw rows when needed.
     *
     * @return MorphMany<Taggable, $this>
     */
    public function taggables(): MorphMany
    {
        return $this->morphMany(Taggable::class, 'taggable');
    }

    /**
     * Tags attached to this subject.
     *
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')
            ->withTimestamps();
    }
}