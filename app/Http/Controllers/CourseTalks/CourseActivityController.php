<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CourseActivityType;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseActivityRequest;
use App\Models\Courses\CourseActivity;
use App\Services\Courses\CourseActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated activity creation. Thin by design: the FormRequest validates,
 * the policy authorizes, and CourseActivityService owns every domain rule
 * (code uniqueness, slug derivation, defaults).
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
            return back()->withInput()->withErrors(['code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('course-talks.activities.index')
            ->with('status', "Actividad \"{$activity->name}\" creada correctamente.");
    }
}
