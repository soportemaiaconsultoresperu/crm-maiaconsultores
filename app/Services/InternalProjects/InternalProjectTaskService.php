<?php

declare(strict_types=1);

namespace App\Services\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Exceptions\InternalProjects\InvalidInternalProjectTask;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for internal project tasks (S2, S3).
 *
 * Keeps the domain invariants every screen relies on: the phase belongs to
 * the task's project, the due date is not before the start, the weight is at
 * least 1, `completed_at` is set exactly while the task is "Terminada", and
 * every status change (including the initial one) is recorded with who and
 * when. Authorization stays in the caller (InternalProjectPolicy::manageWork).
 */
class InternalProjectTaskService
{
    /** Attributes a caller may write; status goes through the status path. */
    private const WRITABLE = [
        'internal_project_phase_id',
        'title',
        'description',
        'responsible_user_id',
        'starts_on',
        'due_on',
        'weight',
        'is_milestone',
        'sort',
    ];

    /**
     * @param  array<string, mixed>  $data  WRITABLE attributes plus optional `status`
     */
    public function createTask(InternalProject $project, array $data, User $user): InternalProjectTask
    {
        $attributes = Arr::only($data, self::WRITABLE);
        $attributes['weight'] ??= 1;

        foreach (['internal_project_phase_id' => 'la fase', 'title' => 'el título', 'starts_on' => 'la fecha de inicio', 'due_on' => 'la fecha de vencimiento'] as $field => $label) {
            if (blank($attributes[$field] ?? null)) {
                throw InvalidInternalProjectTask::missing($field, $label);
            }
        }

        $this->assertValid($project, $attributes);
        $status = $this->statusFrom($data['status'] ?? null) ?? TaskStatus::Pending;

        return DB::transaction(function () use ($project, $attributes, $status, $user): InternalProjectTask {
            $now = CarbonImmutable::now();

            $task = new InternalProjectTask($attributes);
            $task->internal_project_id = $project->id;
            $task->status = $status;
            $task->completed_at = $status === TaskStatus::Done ? $now : null;
            $task->created_by = $user->id;
            $task->updated_by = $user->id;
            $task->save();

            $this->recordChange($task, null, $status, $user, $now);

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data  any subset of WRITABLE plus optional `status`
     */
    public function updateTask(InternalProjectTask $task, array $data, User $user): InternalProjectTask
    {
        $attributes = Arr::only($data, self::WRITABLE);
        $status = $this->statusFrom($data['status'] ?? null);

        $this->assertValid($task->project, array_merge([
            'internal_project_phase_id' => $task->internal_project_phase_id,
            'starts_on' => $task->starts_on?->toDateString(),
            'due_on' => $task->due_on?->toDateString(),
            'weight' => $task->weight,
        ], $attributes));

        return DB::transaction(function () use ($task, $attributes, $status, $user): InternalProjectTask {
            $locked = $this->lock($task);
            $locked->fill($attributes);
            $locked->updated_by = $user->id;
            $locked->save();

            if ($status !== null) {
                $this->applyStatus($locked, $status, $user);
            }

            $task->setRawAttributes($locked->getAttributes(), true);

            return $task;
        });
    }

    /**
     * Change the status on the locked row, keep `completed_at` in sync and
     * record the transition. Setting the current status is a no-op.
     */
    public function changeStatus(InternalProjectTask $task, TaskStatus $status, User $user): InternalProjectTask
    {
        return DB::transaction(function () use ($task, $status, $user): InternalProjectTask {
            $locked = $this->lock($task);
            $this->applyStatus($locked, $status, $user);

            $task->setRawAttributes($locked->getAttributes(), true);

            return $task;
        });
    }

    private function applyStatus(InternalProjectTask $locked, TaskStatus $status, User $user): void
    {
        $from = $locked->status;
        if ($from === $status) {
            return;
        }

        $now = CarbonImmutable::now();
        $locked->status = $status;
        $locked->completed_at = $status === TaskStatus::Done ? $now : null;
        $locked->updated_by = $user->id;
        $locked->save();

        $this->recordChange($locked, $from, $status, $user, $now);
    }

    private function lock(InternalProjectTask $task): InternalProjectTask
    {
        return InternalProjectTask::query()->lockForUpdate()->findOrFail($task->getKey());
    }

    private function recordChange(InternalProjectTask $task, ?TaskStatus $from, TaskStatus $to, User $user, CarbonImmutable $at): void
    {
        $task->statusChanges()->create([
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user->id,
            'changed_at' => $at,
        ]);
    }

    private function statusFrom(mixed $status): ?TaskStatus
    {
        if ($status === null || $status === '') {
            return null;
        }

        return $status instanceof TaskStatus ? $status : TaskStatus::from((string) $status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(InternalProject $project, array $attributes): void
    {
        $phaseInProject = InternalProjectPhase::query()
            ->whereKey($attributes['internal_project_phase_id'] ?? null)
            ->where('internal_project_id', $project->id)
            ->exists();
        if (! $phaseInProject) {
            throw InvalidInternalProjectTask::phaseOutsideProject();
        }

        $weight = $attributes['weight'] ?? 1;
        if (filter_var($weight, FILTER_VALIDATE_INT) === false || (int) $weight < 1 || (int) $weight > 65535) {
            throw InvalidInternalProjectTask::invalidWeight();
        }

        $starts = CarbonImmutable::parse($attributes['starts_on'])->toDateString();
        $due = CarbonImmutable::parse($attributes['due_on'])->toDateString();
        if ($due < $starts) {
            throw InvalidInternalProjectTask::dueBeforeStart();
        }
    }
}
