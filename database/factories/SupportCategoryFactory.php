<?php

namespace Database\Factories;

use App\Models\SupportCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupportCategory> */
class SupportCategoryFactory extends Factory
{
    protected $model = SupportCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => null,
            'is_active' => true,
            'sort' => 1,
        ];
    }
}
