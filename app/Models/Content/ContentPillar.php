<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPillar extends ContentCatalog
{
    /** @var list<string> */
    protected $fillable = ['content_project_id', 'name', 'slug', 'sort', 'is_active'];

    /** @return BelongsTo<ContentProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class, 'content_project_id');
    }

    /** @return HasMany<ContentTopic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(ContentTopic::class, 'content_pillar_id');
    }
}
