<?php

namespace App\Http\Controllers\CourseTalks;

use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseAttendanceRequest;
use App\Models\Courses\CourseAttendance;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseSession;
use App\Services\Courses\CourseAttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated attendance matrix of one edition: the edition's enrollments as
 * rows, its sessions as columns, and one bulk submit that marks the submitted
 * cells.
 *
 * Thin by design: CourseEditionPolicy authorizes (view to read the matrix,
 * manageAttendance to mark it), StoreCourseAttendanceRequest validates only the
 * shape of the submitted cells, and CourseAttendanceService — called once per
 * cell with the authenticated actor — owns every attendance rule: the valid
 * statuses, the same-edition check, the talk participation refresh and the
 * eligibility trigger. The controller only decides how a rejected cell is
 * reported, because a stale or tampered payload must never become an HTTP 500.
 */
class CourseAttendanceController extends Controller
{
    public function __construct(
        private readonly CourseAttendanceService $attendance,
    ) {}

    public function index(CourseEdition $edition): View
    {
        Gate::authorize('view', $edition);

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

        $attendance = CourseAttendance::query()
            ->whereIn('course_session_id', $sessions->modelKeys())
            ->whereIn('course_enrollment_id', $enrollments->modelKeys())
            ->get(['course_enrollment_id', 'course_session_id', 'status'])
            // [enrollment_id => [session_id => status]] so a cell without a row
            // renders as unmarked instead of forcing the view to query.
            ->groupBy('course_enrollment_id')
            ->map(static fn ($rows): array => $rows->pluck('status', 'course_session_id')->all())
            ->all();

        return view('course-talks.editions.attendance', [
            'edition' => $edition->load('activity'),
            'sessions' => $sessions,
            'enrollments' => $enrollments,
            'attendance' => $attendance,
        ]);
    }

    public function store(StoreCourseAttendanceRequest $request, CourseEdition $edition): RedirectResponse
    {
        Gate::authorize('manageAttendance', $edition);

        foreach ($request->cells() as $cell) {
            $session = CourseSession::find($cell['session_id']);
            $enrollment = CourseEnrollment::find($cell['enrollment_id']);

            if ($session === null || $enrollment === null) {
                // A cell whose row disappeared after the matrix was rendered is
                // stale input, not a domain error: report it as a visible
                // validation failure instead of letting it 404 or 500.
                return back()->withInput()->withErrors([
                    'attendance' => 'No se pudo guardar la asistencia: una de las sesiones o participantes ya no está disponible. Actualice la matriz e intente nuevamente.',
                ]);
            }

            try {
                $this->attendance->mark($session, $enrollment, $cell['status'], $request->user());
            } catch (InvalidCourseEditionData $exception) {
                // Stale or mismatched input (for example a session from another
                // edition) must be visible to the user, never an HTTP 500. The
                // message is the service's own, so the rule stays single-sourced.
                return back()->withInput()->withErrors(['attendance' => $exception->getMessage()]);
            }
        }

        return redirect()
            ->route('course-talks.attendance.index', $edition)
            ->with('status', 'Asistencia actualizada correctamente.');
    }
}
