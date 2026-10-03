<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Optional grouping of a project's custom fields. A section is a catalog-shaped
 * row (name, slug, sort, active flag) whose slug is unique per project.
 *
 * @use HasFactory<\Database\Factories\Content\ContentFieldSectionFactory>
 */
class ContentFieldSection extends ContentCatalog
{
    /** @var list<string> */
    protected $fillable = [
        'content_project_id',
        'name',
        'slug',
        'sort',
        'is_active',
    ];

    /** @return BelongsTo<ContentProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class, 'content_project_id');
    }

    /** @return HasMany<ContentFieldDefinition, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ContentFieldDefinition::class, 'content_field_section_id');
    }
}
