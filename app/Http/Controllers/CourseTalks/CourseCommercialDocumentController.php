<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CommercialDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCommercialDocumentRequest;
use App\Http\Requests\CourseTalks\UploadCommercialDocumentRequest;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Notification\OutboundDelivery;
use App\Services\Courses\CourseCommercialDocumentService;
use App\Services\Courses\CourseDocumentDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authenticated commercial documents of one edition: the list of the edition's
 * registered facturas/boletas/recibos, their private attachment upload, and the
 * per-enrollment registration form with the IGV breakdown shown before the user
 * commits, plus the read-only delivery history and recipient data the delivery
 * actions of CourseCommercialDocumentDeliveryController render from.
 *
 * Thin by design: CourseEditionPolicy::view authorizes the list,
 * `course-talks.commercial-documents.manage` authorizes registration and upload
 * (both FormRequests ask for it and CourseCommercialDocumentService re-asks it
 * for the actor it writes for), and the service owns every rule — IGV rate
 * selection, subtotal arithmetic over the enrollment charges, the aggregation
 * of a group purchase, the enrollment-or-group constraint, the private file
 * storage. Nothing here reimplements money, and nothing renders money the
 * service did not return.
 *
 * A group purchase registers through its own endpoint (the same split as
 * `enrollments.groups.store`): the payload keeps its own target field and the
 * bound group always wins, while the money comes from
 * CourseCommercialDocumentService::calculateGroupCharges(). A group the service
 * refuses (no billable enrollments, zero aggregated subtotal) shows the
 * service's reason instead of a control that cannot succeed.
 *
 * Commercial delivery actions (email, WhatsApp handoff, confirmation) are
 * implemented by CourseCommercialDocumentDeliveryController (unit 6.f-2b). This
 * surface supplies the listing data they need — the append-only delivery history
 * per comprobante, the payer data the controls are prefilled from, and the
 * per-comprobante deliverability verdict read from
 * CourseDocumentDeliveryService::hasStreamableCommercialDocument(), so no control
 * is offered for a comprobante that predicate would refuse.
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
            // `group.payerCustomer` is eager loaded because the delivery actions
            // offered below are prefilled from the group's own payer; the
            // enrollment path already carries its participant.
            ->with(['document', 'group.payerCustomer', 'enrollment.participant'])
            ->orderBy('id')
            ->get();

        [$breakdowns, $breakdownFailures] = $this->breakdowns($enrollments, $this->enrollmentBreakdown(...));

        $groups = CourseEnrollmentGroup::query()
            ->where('course_edition_id', $edition->id)
            ->orderBy('id')
            ->get();

        [$groupBreakdowns, $groupBreakdownFailures] = $this->breakdowns($groups, $this->groupBreakdown(...));

        // The read-only face of the delivery service. This surface only asks its
        // deliverability predicate, which touches no transport, but the service
        // requires a mail closure in its constructor: the same unused placeholder
        // the delivery controllers pass.
        $deliveryService = new CourseDocumentDeliveryService(static fn (): bool => true);

        return view('course-talks.editions.commercial-documents', [
            'edition' => $edition->load('activity'),
            'enrollments' => $enrollments,
            'groups' => $groups,
            'commercialDocuments' => $commercialDocuments,
            'types' => CommercialDocumentType::cases(),
            'breakdowns' => $breakdowns,
            'breakdownFailures' => $breakdownFailures,
            'groupBreakdowns' => $groupBreakdowns,
            'groupBreakdownFailures' => $groupBreakdownFailures,
            'currency' => (string) config('courses.default_currency'),
            // The verdict of the domain's own deliverability predicate, read from
            // the service instead of reimplemented here: the delivery controls are
            // offered only for a comprobante it would actually accept, so a
            // registered one whose private file is missing gets no action that
            // could only be refused.
            'deliverability' => $commercialDocuments
                ->mapWithKeys(fn (CourseCommercialDocument $commercial): array => [
                    $commercial->id => $deliveryService->hasStreamableCommercialDocument($commercial),
                ])
                ->all(),
            // Delivery history is read once for the whole edition. The append-only
            // ledger is the only source of delivery truth and this surface never
            // writes it: the domain service records every attempt.
            'deliveries' => OutboundDelivery::query()
                ->where('related_entity_type', CourseCommercialDocument::class)
                ->whereIn('related_entity_id', $commercialDocuments->pluck('id'))
                ->orderByDesc('id')
                ->get()
                ->groupBy('related_entity_id'),
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

    public function storeGroup(StoreCommercialDocumentRequest $request, CourseEnrollmentGroup $group): RedirectResponse
    {
        $group->loadMissing('edition');

        try {
            $this->commercialDocuments->register(
                CommercialDocumentType::from($request->validated('type')),
                // The endpoint owns the target: the bound group always wins, so a
                // tampered payload cannot retarget another payer, while any extra
                // target it carries still reaches the domain rule that refuses
                // two payers. The charges are never part of the payload: the
                // service aggregates them from the group's own enrollments.
                ['course_enrollment_group_id' => $group->id] + $request->validated(),
                $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            // Includes the groups the service refuses to bill at all (no
            // billable enrollments, zero aggregated subtotal): the user reads
            // the service's own reason and no zero-value row is written.
            return $this->backToListing($group->edition)
                ->withInput()
                ->withErrors(['commercial_document' => $exception->getMessage()]);
        }

        return $this->backToListing($group->edition)
            ->with('status', 'Comprobante del grupo registrado. Adjunte el archivo emitido para completarlo.');
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
     * The pre-submit breakdown of every target, produced only by the service:
     * this method selects no rate and does no arithmetic.
     *
     * A target whose stored charges cannot produce a valid breakdown (a discount
     * larger than the charged total, a group with nothing billable) keeps the
     * service's own message instead of a server error, and the surface then
     * offers no registration form for it.
     *
     * @param  Collection<int, CourseEnrollment|CourseEnrollmentGroup>  $targets
     * @param  callable(CourseEnrollment|CourseEnrollmentGroup, CommercialDocumentType): array<string, string>  $ofType
     * @return array{0: array<int, array<string, array<string, string>>>, 1: array<int, string>}
     */
    private function breakdowns(Collection $targets, callable $ofType): array
    {
        $breakdowns = [];
        $failures = [];

        foreach ($targets as $target) {
            foreach (CommercialDocumentType::cases() as $type) {
                try {
                    $breakdowns[$target->id][$type->value] = $ofType($target, $type);
                } catch (InvalidArgumentException $exception) {
                    $failures[$target->id] = $exception->getMessage();
                    unset($breakdowns[$target->id]);

                    break;
                }
            }
        }

        return [$breakdowns, $failures];
    }

    /**
     * The service's own result for that enrollment's charges and every supported
     * document type, keyed by type value and rendered without transformation.
     *
     * @return array<string, string>
     */
    private function enrollmentBreakdown(CourseEnrollment $enrollment, CommercialDocumentType $type): array
    {
        return $this->commercialDocuments->calculateCharges(
            $type,
            (string) $enrollment->activity_price_amount,
            (string) $enrollment->certificate_charge_amount,
            (string) $enrollment->discount_amount,
        );
    }

    /**
     * The service's own result for that group purchase — the aggregation of its
     * billable enrollments — for every supported document type.
     *
     * @return array<string, string>
     */
    private function groupBreakdown(CourseEnrollmentGroup $group, CommercialDocumentType $type): array
    {
        return $this->commercialDocuments->calculateGroupCharges($type, $group);
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
