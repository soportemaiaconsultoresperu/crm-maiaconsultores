<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A selectable option of a single/multiple selection field. Slug is unique per
 * definition; the row is deactivated, never deleted, so stored option ids keep
 * resolving their label.
 *
 * @use HasFactory<\Database\Factories\Content\ContentFieldOptionFactory>
 */
class ContentFieldOption extends ContentCatalog
{
    /** @var list<string> */
    protected $fillable = [
        'content_field_definition_id',
        'label',
        'slug',
        'sort',
        'is_active',
    ];

    /** @return BelongsTo<ContentFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ContentFieldDefinition::class, 'content_field_definition_id');
    }
}
