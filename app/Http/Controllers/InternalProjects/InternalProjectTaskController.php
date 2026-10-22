<?php

namespace App\Http\Controllers\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Exceptions\InternalProjects\InvalidInternalProjectTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalProjects\InternalProjectTaskRequest;
use App\Http\Requests\InternalProjects\InternalProjectTaskStatusRequest;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectTask;
use App\Services\InternalProjects\InternalProjectTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Tasks of an internal project and their status, managed by the team
 * (manageWork). Every write goes through InternalProjectTaskService so the
 * status history stays audited; the task is bound scoped to the project.
 */
class InternalProjectTaskController extends Controller
{
    public function __construct(private readonly InternalProjectTaskService $tasks) {}

    public function store(InternalProjectTaskRequest $request, InternalProject $internalProject): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        try {
            $task = $this->tasks->createTask($internalProject, $request->validated(), $request->user());
        } catch (InvalidInternalProjectTask $e) {
            return back()->withErrors([$e->field() ?? 'title' => $e->getMessage()])->withInput();
        }

        return $this->toTask($internalProject, $task)->with('status', 'Tarea agregada.');
    }

    public function update(InternalProjectTaskRequest $request, InternalProject $internalProject, InternalProjectTask $task): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        try {
            $this->tasks->updateTask($task, $request->validated(), $request->user());
        } catch (InvalidInternalProjectTask $e) {
            return back()->withErrors([$e->field() ?? 'title' => $e->getMessage()])->withInput();
        }

        return $this->toTask($internalProject, $task)->with('status', 'Tarea actualizada.');
    }

    public function status(InternalProjectTaskStatusRequest $request, InternalProject $internalProject, InternalProjectTask $task): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        $this->tasks->changeStatus($task, TaskStatus::from($request->validated('status')), $request->user());

        return $this->toTask($internalProject, $task)->with('status', 'Estado de la tarea actualizado.');
    }

    public function destroy(InternalProject $internalProject, InternalProjectTask $task): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        $task->delete();

        return redirect()->route('internal-projects.show', $internalProject)
            ->with('status', 'Tarea eliminada.');
    }

    private function toTask(InternalProject $project, InternalProjectTask $task): RedirectResponse
    {
        return redirect()->to(route('internal-projects.show', $project).'#task-'.$task->id);
    }
}
