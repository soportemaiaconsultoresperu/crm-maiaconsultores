<?php

namespace Database\Factories\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tasks always land in their phase's project. A "Terminada" status gets a
 * `completed_at` unless one is given, mirroring InternalProjectTaskService.
 *
 * @extends Factory<InternalProjectTask>
 */
class InternalProjectTaskFactory extends Factory
{
    protected $model = InternalProjectTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_project_phase_id' => InternalProjectPhaseFactory::new(),
            'internal_project_id' => fn (array $attributes) => InternalProjectPhase::query()
                ->findOrFail($attributes['internal_project_phase_id'])->internal_project_id,
            'title' => 'Tarea '.fake()->words(3, true),
            'description' => null,
            'responsible_user_id' => null,
            'starts_on' => '2026-11-01',
            'due_on' => '2026-11-15',
            'status' => TaskStatus::Pending,
            'weight' => 1,
            'is_milestone' => false,
            'completed_at' => fn (array $attributes) => self::isDone($attributes['status']) ? now() : null,
            'sort' => 0,
        ];
    }

    public function milestone(): static
    {
        return $this->state(fn (): array => ['is_milestone' => true]);
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['status' => TaskStatus::Done]);
    }

    private static function isDone(mixed $status): bool
    {
        return $status === TaskStatus::Done || $status === TaskStatus::Done->value;
    }
}
