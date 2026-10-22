<?php

namespace Database\Factories\InternalProjects;

use App\Enums\InternalProjects\ProjectStatus;
use App\Models\InternalProjects\InternalProject;
use App\Models\User;
use App\Support\Content\ProjectColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalProject> */
class InternalProjectFactory extends Factory
{
    protected $model = InternalProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Proyecto '.fake()->words(2, true),
            'code' => 'PI-'.fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(array_keys(ProjectColor::PALETTE)),
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-02-28',
            'status' => ProjectStatus::Planned,
            'responsible_user_id' => User::factory(),
            'audience_type' => InternalProject::AUDIENCE_ALL,
            'created_by' => fn (array $attributes) => $attributes['responsible_user_id'],
        ];
    }

    /** Visible only to the team, the creator and admins. */
    public function private(): static
    {
        return $this->state(fn (): array => ['audience_type' => InternalProject::AUDIENCE_USERS]);
    }
}
