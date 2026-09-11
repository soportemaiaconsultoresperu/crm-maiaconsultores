<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CourseModality;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseEditionRequest;
use App\Http\Requests\CourseTalks\SyncEditionSessionsRequest;
use App\Http\Requests\CourseTalks\SyncEditionTeachersRequest;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Services\Courses\CourseEditionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated edition creation scoped to its parent activity, plus the
 * edition's teacher and session list management.
 *
 * Thin by design: each FormRequest validates the attributes the matching
 * CourseEditionService method consumes, the policy authorizes, and the service
 * owns every edition rule (code uniqueness, modality requirements,
 * state/currency/delivery-due defaults, teacher full-list replace, session
 * upsert by array position).
 */
class CourseEditionController extends Controller
{
    public function __construct(
        private readonly CourseEditionService $editions,
    ) {}

    public function create(CourseActivity $activity): View
    {
        Gate::authorize('create', CourseEdition::class);

        return view('course-talks.editions.create', [
            'activity' => $activity,
            'modalities' => CourseModality::cases(),
        ]);
    }

    public function store(StoreCourseEditionRequest $request, CourseActivity $activity): RedirectResponse
    {
        Gate::authorize('create', CourseEdition::class);

        try {
            $edition = $this->editions->create($activity, $request->validated());
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors($this->fieldErrors($exception));
        }

        return redirect()
            ->route('course-talks.editions.show', $edition)
            ->with('status', "Edición ".($edition->code ?: 'sin código')." creada correctamente.");
    }

    /**
     * Teacher management view: the current teachers plus the full-list form
     * that replaces them.
     */
    public function teachers(CourseEdition $edition): View
    {
        Gate::authorize('update', CourseEdition::class);

        return view('course-talks.editions.show', [
            'edition' => $edition->load('activity'),
            // Passed explicitly (never as a lazy relation load) so the read-only
            // showEdition() path keeps rendering without touching this table.
            'teachers' => $edition->teachers()->orderBy('sort_order')->get(),
        ]);
    }

    public function syncTeachers(SyncEditionTeachersRequest $request, CourseEdition $edition): RedirectResponse
    {
        Gate::authorize('update', CourseEdition::class);

        try {
            $this->editions->syncTeachers($edition, $request->teachersForSync());
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors($this->fieldErrors($exception));
        }

        return redirect()
            ->route('course-talks.editions.teachers', $edition)
            ->with('status', 'Docentes de la edición actualizados correctamente.');
    }

    /**
     * Session management view: the current sessions (with their dates and times)
     * plus the form that upserts them by array position.
     */
    public function sessions(CourseEdition $edition): View
    {
        Gate::authorize('update', CourseEdition::class);

        return view('course-talks.editions.show', [
            'edition' => $edition->load('activity'),
            // Passed explicitly (never as a lazy relation load) so the read-only
            // showEdition() path keeps rendering without touching this table.
            'sessions' => $edition->sessions()->orderBy('sort_order')->get(),
        ]);
    }

    public function syncSessions(SyncEditionSessionsRequest $request, CourseEdition $edition): RedirectResponse
    {
        Gate::authorize('update', CourseEdition::class);

        try {
            $this->editions->syncSessions($edition, $request->sessionsForSync());
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors($this->fieldErrors($exception));
        }

        return redirect()
            ->route('course-talks.editions.sessions', $edition)
            ->with('status', 'Sesiones de la edición actualizadas correctamente.');
    }

    /**
     * The service owns the rule and also names the field the user has to fix, so
     * this only translates the exception into an error-bag entry. An exception
     * that carries no field is reported generically under `edition` instead of
     * being attributed to a location field by guesswork.
     *
     * @return array<string, string>
     */
    private function fieldErrors(InvalidCourseEditionData $exception): array
    {
        return [($exception->field() ?? 'edition') => $exception->getMessage()];
    }
}
