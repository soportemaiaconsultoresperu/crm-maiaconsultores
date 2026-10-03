<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentObjective extends ContentCatalog
{
    /** @var list<string> */
    protected $fillable = ['content_project_id', 'name', 'slug', 'sort', 'is_active'];

    /** @return BelongsTo<ContentProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class, 'content_project_id');
    }
}
