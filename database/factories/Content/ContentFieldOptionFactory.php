<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentFieldOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentFieldOption> */
class ContentFieldOptionFactory extends Factory
{
    protected $model = ContentFieldOption::class;

    public function definition(): array
    {
        $label = fake('es_PE')->unique()->word();

        return [
            'content_field_definition_id' => ContentFieldDefinitionFactory::new()->listType(),
            'label' => $label,
            'slug' => Str::slug($label).'-'.fake()->unique()->numberBetween(1, 999999),
            'sort' => 0,
            'is_active' => true,
        ];
    }
}
