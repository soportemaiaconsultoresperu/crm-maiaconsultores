<?php

namespace Database\Factories\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A text value whose content item and field definition share one project, so
 * the row is always project-consistent. Use `for()` with a definition or an
 * item to attach it to an existing row.
 *
 * @extends Factory<ContentFieldValue>
 */
class ContentFieldValueFactory extends Factory
{
    protected $model = ContentFieldValue::class;

    public function definition(): array
    {
        $project = ContentProjectFactory::new();

        return [
            'content_item_id' => ContentItemFactory::new()->for($project, 'project'),
            'content_field_definition_id' => ContentFieldDefinitionFactory::new()
                ->for($project, 'project')
                ->ofType(ContentFieldType::Text),
            'value_text' => fake()->sentence(),
            'value_number' => null,
            'value_date' => null,
            'content_field_option_id' => null,
        ];
    }
}
