<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The project is derived through pillar; no redundant project id is stored. */
class ContentTopic extends ContentCatalog
{
    /** @var list<string> */
    protected $fillable = ['content_pillar_id', 'name', 'slug', 'sort', 'is_active'];

    /** @return BelongsTo<ContentPillar, $this> */
    public function pillar(): BelongsTo
    {
        return $this->belongsTo(ContentPillar::class, 'content_pillar_id');
    }
}
