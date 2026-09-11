<?php

namespace App\Http\Controllers\CourseTalks;

use App\Http\Controllers\Controller;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class CourseActivityReadController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', CourseActivity::class);

        return view('course-talks.activities.index', [
            'activities' => CourseActivity::query()
                ->withCount('editions')
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(CourseActivity $activity): View
    {
        Gate::authorize('view', $activity);

        return view('course-talks.activities.show', [
            'activity' => $activity->load(['editions' => fn ($query) => $query->orderByDesc('starts_on')]),
        ]);
    }

    public function showEdition(CourseEdition $edition): View
    {
        Gate::authorize('view', $edition);

        return view('course-talks.editions.show', [
            'edition' => $edition->load(['activity']),
        ]);
    }
}
