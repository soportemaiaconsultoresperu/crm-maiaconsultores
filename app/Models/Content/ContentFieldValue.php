<?php

namespace App\Models\Content;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A typed custom-field value stored against one content item and one field
 * definition (T6b). The definition's stable `key`, never its label, is the
 * identity used to read and write the value, so relabelling a field leaves the
 * stored row untouched.
 *
 * The row carries one storage column per shape the type needs: `value_text`,
 * `value_number`, `value_date` and `content_field_option_id` for a single
 * selection. A multiple-selection field stores its chosen options in the
 * `content_field_value_option` pivot through `selectedOptions()`.
 *
 * Values follow their content item: they are not independently soft-deleted,
 * so there is no SoftDeletes trait here.
 *
 * @use HasFactory<\Database\Factories\Content\ContentFieldValueFactory>
 */
class ContentFieldValue extends Model
{
    use HasAuditColumns, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'content_item_id',
        'content_field_definition_id',
        'value_text',
        'value_number',
        'value_date',
        'content_field_option_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:2',
            'value_date' => 'date',
            'content_field_option_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    /** @return BelongsTo<ContentFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ContentFieldDefinition::class, 'content_field_definition_id');
    }

    /**
     * The single selected option of a single-selection value, if any.
     *
     * @return BelongsTo<ContentFieldOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ContentFieldOption::class, 'content_field_option_id');
    }

    /**
     * Chosen options of a multiple-selection value.
     *
     * @return BelongsToMany<ContentFieldOption, $this>
     */
    public function selectedOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            ContentFieldOption::class,
            'content_field_value_option',
            'content_field_value_id',
            'content_field_option_id',
        );
    }
}
