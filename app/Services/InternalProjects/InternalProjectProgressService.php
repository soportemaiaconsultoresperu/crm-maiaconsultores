<?php

declare(strict_types=1);

namespace App\Services\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Progress of internal projects and phases by task status (S3).
 *
 * percent = floor(sum(weight of "Terminada" tasks) / sum(weight of tasks) × 100),
 * 0 when there are no tasks; flooring keeps 100 % for fully finished work only.
 * Soft-deleted tasks never count.
 *
 * Every method reuses the loaded `tasks` (and `phases`) relations when present,
 * so a dashboard that eager loads `with(['phases', 'tasks'])` renders without
 * N+1 queries; otherwise a single query fetches the tasks.
 *
 * @phpstan-type Progress array{percent: int, total: int, terminadas: int, en_curso: int, bloqueadas: int, pendientes: int, atrasadas: int, weight_total: int, weight_done: int}
 */
class InternalProjectProgressService
{
    /**
     * @return Progress
     */
    public function projectProgress(InternalProject $project, ?CarbonInterface $now = null): array
    {
        return $this->summarize($this->projectTasks($project), $now);
    }

    /**
     * @return Progress
     */
    public function phaseProgress(InternalProjectPhase $phase, ?CarbonInterface $now = null): array
    {
        $tasks = $phase->relationLoaded('tasks') ? $phase->tasks : $phase->tasks()->get();

        return $this->summarize($tasks, $now);
    }

    /**
     * Progress of every phase of the project, keyed by phase id, in phase
     * order. Phases without tasks report 0 %.
     *
     * @return array<int, Progress>
     */
    public function phasesProgress(InternalProject $project, ?CarbonInterface $now = null): array
    {
        $byPhase = $this->projectTasks($project)->groupBy('internal_project_phase_id');
        $phases = $project->relationLoaded('phases') ? $project->phases : $project->phases()->get();

        $progress = [];
        foreach ($phases as $phase) {
            $progress[$phase->id] = $this->summarize($byPhase->get($phase->id, collect()), $now);
        }

        return $progress;
    }

    /**
     * Upcoming milestones that are not done yet (overdue ones included, they
     * are still pending), ordered by due date.
     *
     * @return Collection<int, InternalProjectTask>
     */
    public function nextMilestones(InternalProject $project, int $limit = 3): Collection
    {
        if ($project->relationLoaded('tasks')) {
            return $project->tasks
                ->filter(fn (InternalProjectTask $task): bool => $task->is_milestone && ! $task->isDone())
                ->sortBy([
                    fn (InternalProjectTask $a, InternalProjectTask $b): int => $a->due_on <=> $b->due_on,
                    fn (InternalProjectTask $a, InternalProjectTask $b): int => $a->id <=> $b->id,
                ])
                ->take($limit)
                ->values();
        }

        return $project->tasks()
            ->milestones()
            ->where('status', '!=', TaskStatus::Done->value)
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, InternalProjectTask>
     */
    private function projectTasks(InternalProject $project): Collection
    {
        return $project->relationLoaded('tasks') ? $project->tasks : $project->tasks()->get();
    }

    /**
     * @param  Collection<int, InternalProjectTask>  $tasks
     * @return Progress
     */
    private function summarize(Collection $tasks, ?CarbonInterface $now): array
    {
        $weightTotal = 0;
        $weightDone = 0;
        $counts = array_fill_keys(TaskStatus::values(), 0);
        $overdue = 0;

        foreach ($tasks as $task) {
            $weight = max(1, (int) $task->weight);
            $weightTotal += $weight;
            $counts[$task->status->value]++;

            if ($task->isDone()) {
                $weightDone += $weight;
            } elseif ($task->isOverdue($now)) {
                $overdue++;
            }
        }

        return [
            'percent' => $weightTotal === 0 ? 0 : intdiv($weightDone * 100, $weightTotal),
            'total' => $tasks->count(),
            'terminadas' => $counts[TaskStatus::Done->value],
            'en_curso' => $counts[TaskStatus::InProgress->value],
            'bloqueadas' => $counts[TaskStatus::Blocked->value],
            'pendientes' => $counts[TaskStatus::Pending->value],
            'atrasadas' => $overdue,
            'weight_total' => $weightTotal,
            'weight_done' => $weightDone,
        ];
    }
}
