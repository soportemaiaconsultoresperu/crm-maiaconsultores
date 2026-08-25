<?php

namespace Database\Factories;

use App\Models\SupportPriority;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupportPriority> */
class SupportPriorityFactory extends Factory
{
    protected $model = SupportPriority::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'color' => 'secondary',
            'description' => null,
            'is_active' => true,
            'sort' => 1,
        ];
    }
}
