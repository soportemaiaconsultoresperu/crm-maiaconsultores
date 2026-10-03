<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentPillar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentPillar> */
class ContentPillarFactory extends Factory
{
    protected $model = ContentPillar::class;

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

    /** Compose a project, its pillar and topics without touching T1's factory. */
    public function withTopics(int $count = 3): static
    {
        return $this->has(ContentTopicFactory::new()->count($count), 'topics');
    }
}
