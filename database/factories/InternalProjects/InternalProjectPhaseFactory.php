<?php

namespace Database\Factories\InternalProjects;

use App\Models\InternalProjects\InternalProjectPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalProjectPhase> */
class InternalProjectPhaseFactory extends Factory
{
    protected $model = InternalProjectPhase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_project_id' => InternalProjectFactory::new(),
            'name' => fake()->randomElement(['Análisis', 'Diseño', 'Desarrollo', 'Pruebas', 'Lanzamiento']),
            'sort' => 0,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }
}
