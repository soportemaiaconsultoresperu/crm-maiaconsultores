<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentObjective;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentObjective> */
class ContentObjectiveFactory extends Factory
{
    protected $model = ContentObjective::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'content_project_id' => ContentProjectFactory::new(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'sort' => 0,
            'is_active' => true,
        ];
    }
}
