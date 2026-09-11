<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\AnnulAcademicDocumentRequest;
use App\Http\Requests\CourseTalks\RegenerateAcademicDocumentRequest;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CertificateQrTokenService;
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
     * User-facing wording for a rejection raised by a domain guard whose own
     * message is developer-facing English. The decision to reject stays in the
     * service; only the displayed sentence is owned here.
     */
    private const REGENERATE_REJECTION = 'No se pudo regenerar el documento: solo un documento vigente puede regenerarse.';

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
            ->with(['participant', 'academicDocuments' => fn ($query) => $query->orderByDesc('id')])
            ->orderBy('id')
            ->get();

        return view('course-talks.editions.documents', [
            'edition' => $edition->load('activity'),
            'enrollments' => $enrollments,
            // Eligibility is evaluated up front so the surface can explain why
            // generation is unavailable before the user acts. The evaluation
            // itself is the service's; this surface never decides eligibility.
            'eligibility' => $enrollments
                ->mapWithKeys(fn (CourseEnrollment $enrollment): array => [$enrollment->id => $this->eligibility->evaluate($enrollment)])
                ->all(),
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
        } catch (InvalidArgumentException) {
            return $this->backToIndex($edition)->withInput()->withErrors(['documents' => self::REGENERATE_REJECTION]);
        }

        return $this->backToIndex($edition)
            ->with('status', 'Documento regenerado correctamente: el anterior quedó reemplazado y su código QR fue revocado.');
    }

    public function annul(AnnulAcademicDocumentRequest $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        Gate::authorize('revoke', $academicDocument);

        $edition = $this->editionOf($academicDocument);

        if ($academicDocument->status !== AcademicDocumentStatus::Current) {
            // Boundary guard. CertificateQrTokenService::revoke() itself has no
            // guard against revoking a document that is no longer vigente, so
            // annulling twice would overwrite the original reason, actor and
            // timestamp (and would flip a replaced document back to annulled,
            // breaking the replacement trace). The surface refuses instead of
            // delegating; the missing domain guard is reported to the parent.
            return $this->backToIndex($edition)->withErrors(['documents' => self::ONLY_CURRENT_CAN_BE_ANNULLED]);
        }

        try {
            $this->qrTokens->revoke($academicDocument, $request->user(), (string) $request->validated('reason'));
        } catch (InvalidArgumentException) {
            return $this->backToIndex($edition)->withInput()->withErrors(['documents' => self::ANNULMENT_REJECTION]);
        }

        return $this->backToIndex($edition)
            ->with('status', 'Documento anulado correctamente: su código QR quedó revocado.');
    }

    private function editionOf(CourseAcademicDocument $document): CourseEdition
    {
        $document->loadMissing('enrollment.edition');

        return $document->enrollment->edition;
    }

    private function backToIndex(CourseEdition $edition): RedirectResponse
    {
        return redirect()->route('course-talks.documents.index', $edition);
    }
}
