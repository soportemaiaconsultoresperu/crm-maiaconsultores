<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\PaymentStatus;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseEnrollmentGroupRequest;
use App\Http\Requests\CourseTalks\StoreCourseEnrollmentRequest;
use App\Http\Requests\CourseTalks\UpdateCourseEnrollmentPaymentStatusRequest;
use App\Models\Contact;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Customer;
use App\Services\Courses\CourseEnrollmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated enrollment surface of one edition: the participant list plus
 * individual and group enrollment, and the payment-status change.
 *
 * Thin by design: each FormRequest validates exactly the attributes the
 * matching CourseEnrollmentService method consumes, the policies authorize
 * (CourseEditionPolicy::view for reading a list, CourseEnrollmentPolicy for
 * every write), and the service owns participant deduplication, minimum-data
 * requirements, duplicate-enrollment rejection, group atomicity and the
 * permitted payment transitions. The Blade views only format what the domain
 * already decided.
 */
class CourseEnrollmentController extends Controller
{
    public function __construct(
        private readonly CourseEnrollmentService $enrollments,
    ) {}

    /**
     * The edition's participants with their enrollment, payment and group-payer
     * data.
     */
    public function index(CourseEdition $edition): View
    {
        Gate::authorize('view', $edition);

        return view('course-talks.enrollments.index', [
            'edition' => $edition->load('activity'),
            'enrollments' => CourseEnrollment::query()
                ->where('course_edition_id', $edition->id)
                ->with(['participant', 'group'])
                ->orderBy('id')
                ->get(),
            // The form offers every status; the legal transitions are the
            // service's business rule, not a view decision.
            'paymentStatuses' => PaymentStatus::cases(),
        ]);
    }

    public function create(CourseEdition $edition): View
    {
        Gate::authorize('create', CourseEnrollment::class);

        return view('course-talks.enrollments.create', [
            'edition' => $edition->load('activity'),
            'contacts' => Contact::query()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'customer_id', 'first_name', 'last_name', 'email']),
            'participants' => CourseParticipant::query()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'document_type', 'document_number']),
            'customers' => Customer::query()
                ->orderBy('code')
                ->get(['id', 'code', 'legal_name', 'trade_name']),
        ]);
    }

    public function store(StoreCourseEnrollmentRequest $request, CourseEdition $edition): RedirectResponse
    {
        Gate::authorize('create', CourseEnrollment::class);

        try {
            $this->enrollments->enroll($edition, $request->participantData());
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors($this->fieldErrors($exception));
        }

        return redirect()
            ->route('course-talks.enrollments.index', $edition)
            ->with('status', 'Participante inscrito correctamente.');
    }

    public function storeGroup(StoreCourseEnrollmentGroupRequest $request, CourseEdition $edition): RedirectResponse
    {
        Gate::authorize('create', CourseEnrollment::class);

        try {
            $enrollments = $this->enrollments->enrollGroup(
                $edition,
                $request->payerData(),
                $request->participantsForEnrollment(),
            );
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors($this->fieldErrors($exception));
        }

        return redirect()
            ->route('course-talks.enrollments.index', $edition)
            ->with('status', 'Grupo de '.count($enrollments).' participantes inscrito correctamente.');
    }

    public function updatePaymentStatus(
        UpdateCourseEnrollmentPaymentStatusRequest $request,
        CourseEnrollment $enrollment,
    ): RedirectResponse {
        Gate::authorize('update', $enrollment);

        try {
            $this->enrollments->changePaymentStatus(
                $enrollment,
                $request->paymentStatus(),
                // The request and this gate already required
                // `course-talks.participants.manage`, which is this surface's
                // authorization for a payment waiver; whether the transition is
                // permitted stays in the service.
                $request->user()->can('course-talks.participants.manage'),
            );
        } catch (InvalidCourseEditionData $exception) {
            return back()->withInput()->withErrors($this->fieldErrors($exception));
        }

        return redirect()
            ->route('course-talks.enrollments.index', $enrollment->course_edition_id)
            ->with('status', 'Estado de pago actualizado correctamente.');
    }

    /**
     * The service owns the rule and also names the field the user has to fix, so
     * this only translates the exception into an error-bag entry. An exception
     * that carries no field is reported under the generic `enrollment` key
     * instead of being attributed to a field by guesswork.
     *
     * @return array<string, string>
     */
    private function fieldErrors(InvalidCourseEditionData $exception): array
    {
        return [($exception->field() ?? 'enrollment') => $exception->getMessage()];
    }
}
