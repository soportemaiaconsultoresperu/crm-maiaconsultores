<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Exceptions\Courses\InvalidCourseDocumentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\AnnulAcademicDocumentRequest;
use App\Http\Requests\CourseTalks\RegenerateAcademicDocumentRequest;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Notification\OutboundDelivery;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\Courses\CourseEligibilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authenticated academic document lifecycle of one edition: the list of
 * enrollments with their expected document type and eligibility, plus the
 * generate / regenerate / annul actions.
 *
 * Thin by design: CourseEditionPolicy::view authorizes the list, `generate`
 * authorizes generation and regeneration, `revoke` authorizes annulment, and the
 * domain services own every rule — eligibility evaluation and document type
 * selection (CourseEligibilityService), filename, code, private PDF and the
 * replacement path (CourseDocumentGenerationService), and the QR token plus the
 * annulment columns (CertificateQrTokenService). This controller only decides
 * how a domain rejection is reported, so no rejection becomes an HTTP 500.
 */
class CourseAcademicDocumentController extends Controller
{
    /**
     * User-facing wording for a rejection raised by the not-current guard, whose
     * own message is developer-facing English. The decision to reject stays in the
     * service; only the displayed sentence is owned here.
     */
    private const REGENERATE_ONLY_CURRENT = 'No se pudo regenerar el documento: solo un documento vigente puede regenerarse.';

    /**
     * Wording for a regeneration refused for a reason this surface cannot name. It
     * never claims a cause it does not know, so a rejection caused by something
     * else can never be reported as the not-current one.
     */
    private const REGENERATE_FALLBACK = 'No se pudo regenerar el documento. Actualice la página, verifique el estado de la matrícula y del documento, y vuelva a intentarlo.';

    private const ANNULMENT_REJECTION = 'No se pudo anular el documento: el motivo de anulación es obligatorio.';

    private const ONLY_CURRENT_CAN_BE_ANNULLED = 'Solo un documento vigente puede anularse. Los documentos anulados o reemplazados conservan su estado y su motivo original.';

    public function __construct(
        private readonly CourseDocumentGenerationService $documents,
        private readonly CertificateQrTokenService $qrTokens,
        private readonly CourseEligibilityService $eligibility,
    ) {}

    public function index(CourseEdition $edition): View
    {
        Gate::authorize('view', $edition);

        $enrollments = CourseEnrollment::query()
            ->where('course_edition_id', $edition->id)
            ->with(['participant', 'academicDocuments' => fn ($query) => $query->orderByDesc('id')->with('document')])
            ->orderBy('id')
            ->get();

        // Every listed document, read once: the deliverability verdict and the
        // delivery history are both keyed by document id.
        $documents = $enrollments->flatMap->academicDocuments;

        // The read-only face of the delivery service. This surface only asks its
        // deliverability predicate, which touches no transport, but the service
        // requires a mail closure in its constructor: the same unused placeholder
        // the delivery controllers pass.
        $deliveryService = new CourseDocumentDeliveryService(static fn (): bool => true);

        return view('course-talks.editions.documents', [
            'edition' => $edition->load('activity'),
            'enrollments' => $enrollments,
            // Eligibility is evaluated up front so the surface can explain why
            // generation is unavailable before the user acts. The evaluation
            // itself is the service's; this surface never decides eligibility.
            'eligibility' => $enrollments
                ->mapWithKeys(fn (CourseEnrollment $enrollment): array => [$enrollment->id => $this->eligibility->evaluate($enrollment)])
                ->all(),
            // The verdict of the domain's own deliverability predicate, read from
            // the service instead of reimplemented here: the delivery controls are
            // offered only for a document it would actually accept, so a current
            // document whose private file is missing gets no action that could
            // only be refused.
            'deliverability' => $documents
                ->mapWithKeys(fn (CourseAcademicDocument $document): array => [
                    $document->id => $deliveryService->hasDeliverableAcademicDocument($document),
                ])
                ->all(),
            // Delivery history is read once for the whole edition. The append-only
            // ledger is the only source of delivery truth and this surface never
            // writes it: the service records every attempt.
            'deliveries' => OutboundDelivery::query()
                ->where('related_entity_type', CourseAcademicDocument::class)
                ->whereIn('related_entity_id', $documents->pluck('id'))
                ->orderByDesc('id')
                ->get()
                ->groupBy('related_entity_id'),
        ]);
    }

    public function store(Request $request, CourseEnrollment $enrollment): RedirectResponse
    {
        Gate::authorize('generate', CourseAcademicDocument::class);

        $enrollment->loadMissing('edition');

        try {
            $this->documents->generate($enrollment, $request->user());
        } catch (InvalidArgumentException $exception) {
            // The service owns the eligibility rule and already reports it in
            // Spanish, so its own message is what the user reads.
            return $this->backToIndex($enrollment->edition)->withErrors(['documents' => $exception->getMessage()]);
        }

        return $this->backToIndex($enrollment->edition)
            ->with('status', 'Documento académico generado correctamente.');
    }

    public function regenerate(RegenerateAcademicDocumentRequest $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        Gate::authorize('generate', CourseAcademicDocument::class);

        $edition = $this->editionOf($academicDocument);

        try {
            $this->documents->regenerate(
                $academicDocument,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (InvalidArgumentException $exception) {
            // The service owns the reason, and it tags it: this surface only
            // translates the reason it knows, so a regeneration refused for
            // something else is never reported as a currency problem.
            return $this->backToIndex($edition)->withInput()->withErrors(['documents' => $this->regenerationRejection($exception)]);
        }

        return $this->backToIndex($edition)
            ->with('status', 'Documento regenerado correctamente: el anterior quedó reemplazado y su código QR fue revocado.');
    }

    public function annul(AnnulAcademicDocumentRequest $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        Gate::authorize('revoke', $academicDocument);

        $edition = $this->editionOf($academicDocument);

        if ($academicDocument->status !== AcademicDocumentStatus::Current) {
            // Boundary guard, defence in depth: the service owns the rule below,
            // but the surface refuses here so the user gets the message without
            // an attempted write. CertificateQrTokenService::revoke() re-reads the
            // persisted status under a lock and refuses anything that is not
            // current, which is the only guard able to see a document that
            // stopped being vigente after this check.
            return $this->backToIndex($edition)->withErrors(['documents' => self::ONLY_CURRENT_CAN_BE_ANNULLED]);
        }

        try {
            $this->qrTokens->revoke($academicDocument, $request->user(), (string) $request->validated('reason'));
        } catch (InvalidArgumentException) {
            return $this->backToIndex($edition)->withInput()->withErrors([
                'documents' => $this->annulmentRejection($academicDocument),
            ]);
        }

        return $this->backToIndex($edition)
            ->with('status', 'Documento anulado correctamente: su código QR quedó revocado.');
    }

    private function editionOf(CourseAcademicDocument $document): CourseEdition
    {
        $document->loadMissing('enrollment.edition');

        return $document->enrollment->edition;
    }

    /**
     * Spanish wording for an annulment the domain refused. The boundary guard
     * above normally reports the not-current case before the service is called,
     * but a document can stop being vigente between that check and the write.
     * The service refuses without writing — its own message is developer-facing
     * English — so the persisted status is re-read here to pick the sentence the
     * user needs, instead of blaming the reason for a rejection it did not cause.
     */
    private function annulmentRejection(CourseAcademicDocument $document): string
    {
        return $document->fresh()?->status === AcademicDocumentStatus::Current
            ? self::ANNULMENT_REJECTION
            : self::ONLY_CURRENT_CAN_BE_ANNULLED;
    }

    /**
     * Spanish wording for a refused regeneration, chosen from the domain's own
     * reason tag instead of the message text. The not-current guard has its own
     * sentence; the eligibility refusal is already reported in Spanish by the
     * service and keeps exactly the wording the generation surface shows, so the
     * two surfaces can never drift apart. Anything unclassified gets a fallback
     * that never claims a cause, replacing the single constant that used to tell
     * every refused user the document was not current.
     */
    private function regenerationRejection(InvalidArgumentException $exception): string
    {
        if (! $exception instanceof InvalidCourseDocumentState) {
            return self::REGENERATE_FALLBACK;
        }

        return match ($exception->reason()) {
            InvalidCourseDocumentState::NOT_CURRENT => self::REGENERATE_ONLY_CURRENT,
            InvalidCourseDocumentState::NOT_ELIGIBLE => $exception->getMessage(),
            default => self::REGENERATE_FALLBACK,
        };
    }

    private function backToIndex(CourseEdition $edition): RedirectResponse
    {
        return redirect()->route('course-talks.documents.index', $edition);
    }
}
