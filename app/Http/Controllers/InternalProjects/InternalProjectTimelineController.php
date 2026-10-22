<?php

namespace App\Http\Controllers\InternalProjects;

use App\Http\Controllers\Controller;
use App\Models\InternalProjects\InternalProject;
use App\Services\InternalProjects\InternalProjectProgressService;
use App\Services\InternalProjects\InternalProjectTimelineBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Timeline / Gantt of an internal project (S4.2). Read only: anyone who may
 * see the project (team or audience) sees its timeline; anyone else gets a
 * 404, never a 403, so the project's existence is not disclosed (S5).
 */
class InternalProjectTimelineController extends Controller
{
    public function show(
        InternalProject $internalProject,
        InternalProjectTimelineBuilder $builder,
        InternalProjectProgressService $progress,
    ): View {
        abort_unless(Gate::allows('view', $internalProject), 404);

        $internalProject->load(['responsible', 'phases.tasks.responsible']);
        $internalProject->setRelation('tasks', $internalProject->phases->flatMap->tasks->values());

        return view('internal-projects.timeline', [
            'project' => $internalProject,
            'timeline' => $builder->build($internalProject),
            'progress' => $progress->projectProgress($internalProject),
        ]);
    }
}
