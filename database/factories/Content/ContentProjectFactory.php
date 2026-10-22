<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentProject>
 */
class ContentProjectFactory extends Factory
{
    protected $model = ContentProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake('es_PE')->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'color' => null,
            'is_active' => true,
            'archived_at' => null,
        ];
    }

    /**
     * An archived project: retired, but not soft-deleted.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'archived_at' => now(),
        ]);
    }
}
