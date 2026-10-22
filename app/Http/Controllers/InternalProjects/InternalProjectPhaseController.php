<?php

namespace App\Http\Controllers\InternalProjects;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalProjects\InternalProjectPhaseRequest;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Phases of an internal project, managed by the team (manageWork). The phase
 * is bound scoped to the project, so one from another project is a 404.
 */
class InternalProjectPhaseController extends Controller
{
    public function store(InternalProjectPhaseRequest $request, InternalProject $internalProject): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        $data = $request->validated();
        $data['sort'] ??= (int) $internalProject->phases()->max('sort') + 1;

        $internalProject->phases()->create($data);

        return redirect()->route('internal-projects.show', $internalProject)
            ->with('status', 'Fase agregada.');
    }

    public function update(InternalProjectPhaseRequest $request, InternalProject $internalProject, InternalProjectPhase $phase): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        $data = $request->validated();
        // An empty "Orden" keeps the current position (the column is NOT NULL).
        $data['sort'] ??= $phase->sort;

        $phase->update($data);

        return redirect()->to(route('internal-projects.show', $internalProject).'#phase-'.$phase->id)
            ->with('status', 'Fase actualizada.');
    }

    public function destroy(InternalProject $internalProject, InternalProjectPhase $phase): RedirectResponse
    {
        Gate::authorize('manageWork', $internalProject);

        if ($phase->tasks()->exists()) {
            return redirect()->to(route('internal-projects.show', $internalProject).'#phase-'.$phase->id)
                ->with('error', 'No se puede eliminar una fase con tareas. Mueva o elimine primero sus tareas.');
        }

        $phase->delete();

        return redirect()->route('internal-projects.show', $internalProject)
            ->with('status', 'Fase eliminada.');
    }
}
