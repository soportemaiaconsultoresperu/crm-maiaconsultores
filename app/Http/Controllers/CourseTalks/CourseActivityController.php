<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CourseActivityType;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseActivityRequest;
use App\Http\Requests\CourseTalks\UpdateCourseActivityRequest;
use App\Models\Courses\CourseActivity;
use App\Services\Courses\CourseActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated activity creation, edition and deletion. Thin by design: the
 * FormRequest validates, the policy authorizes, and CourseActivityService owns
 * every domain rule (code uniqueness, slug derivation, defaults, the frozen type
 * and the "no dictados" delete guard).
 */
class CourseActivityController extends Controller
{
    public function __construct(
        private readonly CourseActivityService $activities,
    ) {}

    public function create(): View
    {
        Gate::authorize('create', CourseActivity::class);

        return view('course-talks.activities.create', [
            'types' => CourseActivityType::cases(),
        ]);
    }

    public function store(StoreCourseActivityRequest $request): RedirectResponse
    {
        Gate::authorize('create', CourseActivity::class);

        try {
            $activity = $this->activities->create($request->validated());
        } catch (InvalidCourseEditionData $exception) {
            // The domain names the field when it knows it, so the message lands under the
            // input the operator must fix instead of always under the code.
            return back()->withInput()->withErrors([$exception->field() ?? 'code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('course-talks.activities.index')
            ->with('status', "Curso o charla \"{$activity->name}\" creado correctamente.");
    }

    public function edit(CourseActivity $activity): View
    {
        Gate::authorize('update', $activity);

        return view('course-talks.activities.edit', [
            'activity' => $activity,
            'types' => CourseActivityType::cases(),
            // The type select is locked once dictados exist; the service refuses
            // the change anyway, the form only avoids offering it.
            'typeLocked' => $activity->editions()->exists(),
        ]);
    }

    public function update(UpdateCourseActivityRequest $request, CourseActivity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        try {
            $activity = $this->activities->update($activity, $request->validated());
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors([$exception->field() ?? 'code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('course-talks.activities.show', $activity)
            ->with('status', "Curso o charla \"{$activity->name}\" actualizado correctamente.");
    }

    public function destroy(CourseActivity $activity): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        try {
            $this->activities->delete($activity);
        } catch (InvalidCourseEditionData $exception) {
            return redirect()
                ->route('course-talks.activities.show', $activity)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('course-talks.activities.index')
            ->with('status', "Curso o charla \"{$activity->name}\" eliminado correctamente.");
    }
}
