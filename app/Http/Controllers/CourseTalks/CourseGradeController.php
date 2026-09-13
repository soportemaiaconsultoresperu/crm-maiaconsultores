<?php

namespace App\Http\Controllers\CourseTalks;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseGradeRequest;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseGrade;
use App\Models\Courses\CourseSession;
use App\Services\Courses\CourseGradeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authenticated grade matrix of one edition: the edition's enrollments as rows,
 * its sessions as columns, and one bulk submit that records the grades typed in
 * the submitted cells.
 *
 * Thin by design: CourseEditionPolicy authorizes (manageGrades guards the matrix
 * and the submission), StoreCourseGradeRequest validates only the shape of the
 * submitted cells, and CourseGradeService — called once per cell with the
 * authenticated actor — owns every grade rule: the same-edition check, the talk
 * rejection, the accepted value range through CourseGradeCalculator, the upsert
 * correction path and the enrollment result recalculation. The controller only
 * decides how a rejected cell is reported, because a stale or tampered payload
 * must never become an HTTP 500.
 */
class CourseGradeController extends Controller
{
    public function __construct(
        private readonly CourseGradeService $grades,
    ) {}

    public function index(CourseEdition $edition): View
    {
        Gate::authorize('manageGrades', $edition);

        $sessions = CourseSession::query()
            ->where('course_edition_id', $edition->id)
            ->orderBy('session_date')
            ->orderBy('sort_order')
            ->get();

        $enrollments = CourseEnrollment::query()
            ->where('course_edition_id', $edition->id)
            ->with('participant')
            ->orderBy('id')
            ->get();

        $grades = CourseGrade::query()
            ->whereIn('course_session_id', $sessions->modelKeys())
            ->whereIn('course_enrollment_id', $enrollments->modelKeys())
            ->get(['course_enrollment_id', 'course_session_id', 'grade'])
            // [enrollment_id => [session_id => grade]] so a cell without a row
            // renders as empty instead of forcing the view to query.
            ->groupBy('course_enrollment_id')
            ->map(static fn (Collection $rows): array => $rows->pluck('grade', 'course_session_id')->all())
            ->all();

        return view('course-talks.editions.grades', [
            'edition' => $edition->load('activity'),
            'sessions' => $sessions,
            'enrollments' => $enrollments,
            'grades' => $grades,
        ]);
    }

    public function store(StoreCourseGradeRequest $request, CourseEdition $edition): RedirectResponse
    {
        Gate::authorize('manageGrades', $edition);

        $cells = $request->cells();

        if ($cells === []) {
            // Nothing was typed: saving nothing and reporting success would be a
            // false confirmation, so the user is told exactly that.
            return redirect()
                ->route('course-talks.grades.index', $edition)
                ->with('status', 'No se enviaron notas para guardar.');
        }

        $descriptions = $this->existingDescriptions($cells);

        foreach ($cells as $cell) {
            $session = CourseSession::find($cell['session_id']);
            $enrollment = CourseEnrollment::find($cell['enrollment_id']);

            if ($session === null || $enrollment === null) {
                // A cell whose row disappeared after the matrix was rendered is
                // stale input, not a domain error: report it as a visible
                // validation failure instead of letting it 404 or 500.
                return back()->withInput()->withErrors([
                    'grades' => 'No se pudo guardar las notas: una de las sesiones o participantes ya no está disponible. Actualice la matriz e intente nuevamente.',
                ]);
            }

            try {
                $this->grades->record(
                    $session,
                    $enrollment,
                    $cell['grade'],
                    // The row's own description is carried through: this surface
                    // has no description field, so re-recording must not clear a
                    // description set anywhere else.
                    $descriptions->get($this->cellKey($enrollment->id, $session->id)),
                    $request->user(),
                );
            } catch (InvalidArgumentException $exception) {
                // Every domain rejection — a session from another edition, a talk
                // edition, an out-of-range or malformed value — must be visible to
                // the user, never an HTTP 500. The message is the service's (or the
                // calculator's) own, so the rule stays single-sourced. Cells
                // accepted before this one are already recorded, exactly as in the
                // attendance matrix, and the re-rendered matrix shows them.
                return back()->withInput()->withErrors(['grades' => $exception->getMessage()]);
            }
        }

        return redirect()
            ->route('course-talks.grades.index', $edition)
            ->with('status', 'Notas actualizadas correctamente.');
    }

    /**
     * The descriptions already stored for the submitted cells, keyed by
     * enrollment-session pair.
     *
     * @param  array<int, array{enrollment_id: int, session_id: int, grade: string}>  $cells
     * @return Collection<string, string|null>
     */
    private function existingDescriptions(array $cells): Collection
    {
        return CourseGrade::query()
            ->whereIn('course_session_id', array_column($cells, 'session_id'))
            ->whereIn('course_enrollment_id', array_column($cells, 'enrollment_id'))
            ->get(['course_enrollment_id', 'course_session_id', 'description'])
            ->keyBy(fn (CourseGrade $grade): string => $this->cellKey($grade->course_enrollment_id, $grade->course_session_id))
            ->map(static fn (CourseGrade $grade): ?string => $grade->description);
    }

    private function cellKey(int|string $enrollmentId, int|string $sessionId): string
    {
        return $enrollmentId.'-'.$sessionId;
    }
}
