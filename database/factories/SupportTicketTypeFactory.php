<?php

namespace Database\Factories;

use App\Models\SupportTicketType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupportTicketType> */
class SupportTicketTypeFactory extends Factory
{
    protected $model = SupportTicketType::class;

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
