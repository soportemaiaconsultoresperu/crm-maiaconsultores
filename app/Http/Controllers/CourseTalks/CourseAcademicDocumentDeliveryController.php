<?php

namespace App\Http\Controllers\CourseTalks;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\ConfirmAcademicWhatsAppSentRequest;
use App\Http\Requests\CourseTalks\OpenAcademicWhatsAppHandoffRequest;
use App\Http\Requests\CourseTalks\SendAcademicDocumentEmailRequest;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Notification\OutboundDelivery;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Email\EmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authenticated delivery actions of an academic document: email, assisted
 * WhatsApp handoff and manual confirmation.
 *
 * Thin by design. `send` on CourseAcademicDocument authorizes all three actions
 * (route gate, form contract and the domain service all ask for the same
 * ability) and CourseDocumentDeliveryService owns every rule — the deliverability
 * precondition, ledger rows, idempotency keys, status transitions, snapshot
 * updates and the `wa.me` URL. This controller only decides how a domain
 * rejection is reported, so no rejection becomes an HTTP 500, and it never
 * rebuilds a URL, validates a recipient or touches the ledger itself.
 *
 * Email goes through the queued path (`queueAcademicEmail()`): the delivery is
 * recorded as an attempt and the document snapshot is only marked `sent` once the
 * transport job records a terminal success — the direct, synchronous
 * `sendAcademicEmail()` is deliberately not exposed by this surface.
 */
class CourseAcademicDocumentDeliveryController extends Controller
{
    /**
     * The domain's deliverability predicate refused the document: it is not
     * current, its QR token is revoked or its private file is gone. Nothing was
     * written, so no delivery is presented as attempted.
     */
    private const NOT_DELIVERABLE = 'Solo un documento vigente con su archivo privado disponible puede entregarse.';

    /**
     * The matching handoff/phone rule rejected the confirmation, so the document
     * stays pending instead of being marked sent on an unmatched attempt.
     */
    private const CONFIRMATION_REJECTION = 'No se pudo confirmar el envío: el teléfono no coincide con el handoff de WhatsApp registrado.';

    private readonly CourseDocumentDeliveryService $deliveries;

    public function __construct(private readonly EmailService $email)
    {
        // The transport closure only belongs to the direct send path. Every
        // action of this surface queues through EmailService, and the terminal
        // sent/failed outcome is recorded by the SendEmailMessage job against the
        // ledger row this controller creates through the service.
        $this->deliveries = new CourseDocumentDeliveryService(static fn (): bool => true);
    }

    public function email(SendAcademicDocumentEmailRequest $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        Gate::authorize('send', CourseAcademicDocument::class);

        $edition = $this->editionOf($academicDocument);

        try {
            $this->deliveries->queueAcademicEmail(
                $academicDocument,
                (string) $request->validated('recipient'),
                $request->user(),
                (string) $request->validated('operation_key'),
                $this->email,
            );
        } catch (InvalidArgumentException) {
            return $this->backToIndex($edition)
                ->withInput()
                ->withErrors(['documents' => self::NOT_DELIVERABLE]);
        }

        return $this->backToIndex($edition)
            ->with('status', 'El documento quedó en cola de envío por correo. La entrega se registrará cuando el envío se confirme.');
    }

    public function whatsapp(OpenAcademicWhatsAppHandoffRequest $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        Gate::authorize('send', CourseAcademicDocument::class);

        $edition = $this->editionOf($academicDocument);

        try {
            $handoff = $this->deliveries->openAcademicWhatsAppHandoff(
                $academicDocument,
                (string) $request->validated('recipient_phone'),
                $request->user(),
                (string) $request->validated('operation_key'),
            );
        } catch (InvalidArgumentException) {
            return $this->backToIndex($edition)
                ->withInput()
                ->withErrors(['documents' => self::NOT_DELIVERABLE]);
        }

        // Opening the handoff leaves the delivery pending: only the manual
        // confirmation below marks the document sent. The prepared message carries
        // a temporary signed document link, so it is handed to the browser as the
        // WhatsApp redirect target only — never rendered, flashed or logged.
        return redirect()->away($handoff['url']);
    }

    public function confirmWhatsApp(ConfirmAcademicWhatsAppSentRequest $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        Gate::authorize('send', CourseAcademicDocument::class);

        $edition = $this->editionOf($academicDocument);
        $handoff = OutboundDelivery::query()->findOrFail((int) $request->validated('handoff'));

        try {
            $this->deliveries->confirmAcademicWhatsAppSent(
                $academicDocument,
                $handoff,
                (string) $request->validated('recipient_phone'),
                $request->user(),
                (string) $request->validated('operation_key'),
            );
        } catch (InvalidArgumentException) {
            return $this->backToIndex($edition)
                ->withInput()
                ->withErrors(['documents' => self::CONFIRMATION_REJECTION]);
        }

        return $this->backToIndex($edition)
            ->with('status', 'Entrega por WhatsApp confirmada y registrada en el historial de entregas.');
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
