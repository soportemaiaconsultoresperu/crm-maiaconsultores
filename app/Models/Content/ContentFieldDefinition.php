<?php

namespace App\Models\Content;

use App\Enums\Content\ContentFieldType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project-defined custom field. The `key` is the stable identifier used to
 * store and read values; the `label` is only presentation, so renaming never
 * touches a stored key. The `type` drives the value rules in ContentFieldType.
 *
 * Fields are deactivated rather than deleted: the row keeps resolving values
 * already stored under its key.
 *
 * @use HasFactory<\Database\Factories\Content\ContentFieldDefinitionFactory>
 */
class ContentFieldDefinition extends ContentCatalog
{
    /** @var list<string> */
    protected $fillable = [
        'content_project_id',
        'content_field_section_id',
        'key',
        'label',
        'type',
        'help_text',
        'required',
        'sort',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'type' => ContentFieldType::class,
            'required' => 'boolean',
        ];
    }

    /** @return BelongsTo<ContentProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class, 'content_project_id');
    }

    /** @return BelongsTo<ContentFieldSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ContentFieldSection::class, 'content_field_section_id');
    }

    /** @return HasMany<ContentFieldOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ContentFieldOption::class, 'content_field_definition_id');
    }

    /**
     * Stored values that reference this definition, one per content item.
     *
     * @return HasMany<ContentFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ContentFieldValue::class, 'content_field_definition_id');
    }

    /**
     * Whether the field type carries a list of options.
     */
    public function acceptsOptions(): bool
    {
        return $this->type->acceptsOptions();
    }
}
