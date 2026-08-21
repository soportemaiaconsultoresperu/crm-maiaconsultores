<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pivot row between a Tag and any Automatable subject.
 *
 * Schema: tag_id, taggable_type, taggable_id. Uniqueness is enforced at
 * the migration level via uq_taggables_tag_subject. Storing as its own
 * model (rather than relying on Eloquent's auto-generated pivot) lets
 * us attach timestamp columns cleanly and gives the Automatable trait a
 * place to expose `taggables()` MorphMany.
 */
class Taggable extends Model
{
    /**
     * Pivot table name — Tag uses ::class as morph alias.
     */
    protected $table = 'taggables';

    public $timestamps = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tag_id',
        'taggable_type',
        'taggable_id',
    ];

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}