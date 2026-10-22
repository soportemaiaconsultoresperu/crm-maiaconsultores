<?php

namespace Database\Factories\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProjectTaskStatusChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalProjectTaskStatusChange> */
class InternalProjectTaskStatusChangeFactory extends Factory
{
    protected $model = InternalProjectTaskStatusChange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_project_task_id' => InternalProjectTaskFactory::new(),
            'from_status' => TaskStatus::Pending,
            'to_status' => TaskStatus::InProgress,
            'user_id' => User::factory(),
            'changed_at' => now(),
        ];
    }
}
