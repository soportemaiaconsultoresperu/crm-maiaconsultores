<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentTopic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentTopic> */
class ContentTopicFactory extends Factory
{
    protected $model = ContentTopic::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'content_pillar_id' => ContentPillarFactory::new(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'sort' => 0,
            'is_active' => true,
        ];
    }
}
