<?php

namespace Database\Factories\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentFieldDefinition> */
class ContentFieldDefinitionFactory extends Factory
{
    protected $model = ContentFieldDefinition::class;

    public function definition(): array
    {
        return [
            'content_project_id' => ContentProjectFactory::new(),
            'content_field_section_id' => null,
            'key' => 'campo_'.fake()->unique()->numberBetween(1, 999999),
            'label' => 'Campo '.fake()->unique()->word(),
            'type' => ContentFieldType::Text,
            'help_text' => null,
            'required' => false,
            'sort' => 0,
            'is_active' => true,
        ];
    }

    public function ofType(ContentFieldType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * A selection-type field. Combined with withOptions() for a usable field.
     */
    public function listType(ContentFieldType $type = ContentFieldType::SingleSelection): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function withOptions(int $count = 3): static
    {
        return $this->has(ContentFieldOptionFactory::new()->count($count), 'options');
    }
}
