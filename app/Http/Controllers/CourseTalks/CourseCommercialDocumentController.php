<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CommercialDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCommercialDocumentRequest;
use App\Http\Requests\CourseTalks\UploadCommercialDocumentRequest;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CourseCommercialDocumentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authenticated commercial documents of one edition: the list of the edition's
 * registered facturas/boletas/recibos, their private attachment upload, and the
 * per-enrollment registration form with the IGV breakdown shown before the user
 * commits.
 *
 * Thin by design: CourseEditionPolicy::view authorizes the list,
 * `course-talks.commercial-documents.manage` authorizes registration and upload
 * (both FormRequests ask for it and CourseCommercialDocumentService re-asks it
 * for the actor it writes for), and the service owns every rule — IGV rate
 * selection, subtotal arithmetic over the enrollment charges, the
 * enrollment-or-group constraint, the private file storage. Nothing here
 * reimplements money, and nothing renders money the service did not return.
 *
 * The group purchase path is intentionally not offered: the service computes
 * charges for ONE payer and exposes no method that aggregates a group's
 * enrollments, so this unit registers for a single enrollment and the group
 * breakdown stays an explicit domain gap for a follow-up.
 *
 * Commercial delivery actions (email, WhatsApp handoff, confirmation) belong to
 * unit 6.f-2 and are not part of this surface.
 */
class CourseCommercialDocumentController extends Controller
{
    public function __construct(
        private readonly CourseCommercialDocumentService $commercialDocuments,
    ) {}

    public function index(CourseEdition $edition): View
    {
        Gate::authorize('view', $edition);

        $enrollments = CourseEnrollment::query()
            ->where('course_edition_id', $edition->id)
            ->with('participant')
            ->orderBy('id')
            ->get();

        // Everything is scoped to the edition by its own payer path: a document
        // belongs to the edition through its enrollment or through its
        // enrollment group, and both paths are filtered here so no other
        // edition's document can leak into this listing.
        $commercialDocuments = CourseCommercialDocument::query()
            ->where(function ($query) use ($edition): void {
                $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('course_edition_id', $edition->id))
                    ->orWhereHas('group', fn ($group) => $group->where('course_edition_id', $edition->id));
            })
            ->with(['document', 'group', 'enrollment.participant'])
            ->orderBy('id')
            ->get();

        [$breakdowns, $breakdownFailures] = $this->breakdowns($enrollments);

        return view('course-talks.editions.commercial-documents', [
            'edition' => $edition->load('activity'),
            'enrollments' => $enrollments,
            'commercialDocuments' => $commercialDocuments,
            'types' => CommercialDocumentType::cases(),
            'breakdowns' => $breakdowns,
            'breakdownFailures' => $breakdownFailures,
            'currency' => (string) config('courses.default_currency'),
        ]);
    }

    public function store(StoreCommercialDocumentRequest $request, CourseEnrollment $enrollment): RedirectResponse
    {
        $enrollment->loadMissing('edition');

        try {
            $this->commercialDocuments->register(
                CommercialDocumentType::from($request->validated('type')),
                // The endpoint owns the target: the bound enrollment always
                // wins, so a tampered payload cannot retarget another
                // enrollment, while any extra target it carries still reaches
                // the domain rule that refuses two payers.
                ['course_enrollment_id' => $enrollment->id] + $request->validated(),
                $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            // The service owns the target and money rules and already reports
            // them in Spanish, so its own message is what the user reads.
            return $this->backToListing($enrollment->edition)
                ->withInput()
                ->withErrors(['commercial_document' => $exception->getMessage()]);
        }

        return $this->backToListing($enrollment->edition)
            ->with('status', 'Comprobante registrado. Adjunte el archivo emitido para completarlo.');
    }

    public function upload(UploadCommercialDocumentRequest $request, CourseCommercialDocument $commercialDocument): RedirectResponse
    {
        $edition = $this->editionOf($commercialDocument);

        try {
            $this->commercialDocuments->upload($commercialDocument, $request->file('file'), $request->user());
        } catch (InvalidArgumentException $exception) {
            // Second line of defence: the request already validated the type and
            // size, and the service re-checks extension, MIME and size before it
            // writes anything.
            return $this->backToListing($edition)->withErrors(['commercial_document' => $exception->getMessage()]);
        }

        return $this->backToListing($edition)
            ->with('status', 'Archivo del comprobante adjuntado correctamente.');
    }

    /**
     * The pre-submit breakdown of every enrollment, produced only by the
     * service: this method selects no rate and does no arithmetic.
     *
     * An enrollment whose stored charges cannot produce a valid subtotal (a
     * discount larger than the charged total) keeps the service's own message
     * instead of a server error, and the surface then offers no registration
     * form for it.
     *
     * @param  Collection<int, CourseEnrollment>  $enrollments
     * @return array{0: array<int, array<string, array<string, string>>>, 1: array<int, string>}
     */
    private function breakdowns(Collection $enrollments): array
    {
        $breakdowns = [];
        $failures = [];

        foreach ($enrollments as $enrollment) {
            try {
                $breakdowns[$enrollment->id] = $this->breakdownFor($enrollment);
            } catch (InvalidArgumentException $exception) {
                $failures[$enrollment->id] = $exception->getMessage();
            }
        }

        return [$breakdowns, $failures];
    }

    /**
     * The service's own result for that enrollment's charges and every supported
     * document type, keyed by type value and rendered without transformation.
     *
     * @return array<string, array<string, string>>
     */
    private function breakdownFor(CourseEnrollment $enrollment): array
    {
        $breakdown = [];

        foreach (CommercialDocumentType::cases() as $type) {
            $breakdown[$type->value] = $this->commercialDocuments->calculateCharges(
                $type,
                (string) $enrollment->activity_price_amount,
                (string) $enrollment->certificate_charge_amount,
                (string) $enrollment->discount_amount,
            );
        }

        return $breakdown;
    }

    private function editionOf(CourseCommercialDocument $commercial): CourseEdition
    {
        $commercial->loadMissing('enrollment.edition', 'group.edition');

        $edition = $commercial->enrollment?->edition ?? $commercial->group?->edition;
        abort_if($edition === null, 404);

        return $edition;
    }

    private function backToListing(CourseEdition $edition): RedirectResponse
    {
        return redirect()->route('course-talks.commercial-documents.index', $edition);
    }
}
