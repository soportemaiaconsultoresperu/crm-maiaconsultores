<?php

namespace App\Http\Controllers\CourseTalks;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\ConfirmCommercialWhatsAppSentRequest;
use App\Http\Requests\CourseTalks\OpenCommercialWhatsAppHandoffRequest;
use App\Http\Requests\CourseTalks\SendCommercialDocumentEmailRequest;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Notification\OutboundDelivery;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Email\EmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authenticated delivery actions of a commercial document: email, assisted
 * WhatsApp handoff and manual confirmation.
 *
 * Thin by design. `send` on CourseCommercialDocument authorizes all three actions
 * (route gate, form contract and the domain service all ask for the same ability,
 * which maps to `course-talks.documents.send`) and CourseDocumentDeliveryService
 * owns every rule — the deliverability precondition, the exactly-one-target rule,
 * ledger rows, idempotency keys, status transitions, snapshot updates and the
 * `wa.me` URL. This controller only decides how a domain rejection is reported,
 * so no rejection becomes an HTTP 500, and it never rebuilds a URL, validates a
 * recipient or touches the ledger itself.
 *
 * Email goes through the queued path (`queueCommercialEmail()`): the attempt is
 * recorded as a ledger row correlated to a real message carrying the
 * comprobante's signed link. The injected-closure
 * `sendCommercialEmail()` — which marks a comprobante `sent` on the verdict of a
 * closure that builds no message — is deliberately NOT exposed by this surface,
 * and neither is the academic channel's `sendAcademicEmail()`.
 */
class CourseCommercialDocumentDeliveryController extends Controller
{
    /**
     * The domain refused the delivery: the comprobante is not registered/sent or
     * its private file is gone. Nothing was written, so no delivery is presented
     * as attempted.
     */
    private const NOT_DELIVERABLE = 'Solo un comprobante registrado con su archivo privado disponible puede entregarse.';

    /**
     * The matching handoff/phone rule rejected the confirmation, so the
     * comprobante stays pending instead of being marked sent on an unmatched
     * attempt.
     */
    private const CONFIRMATION_REJECTION = 'No se pudo confirmar el envío: el teléfono no coincide con el handoff de WhatsApp registrado.';

    /**
     * The handoff is not an open WhatsApp attempt for this comprobante, so there is
     * nothing to discard and no delivery may be rewritten.
     */
    private const DISCARD_REJECTION = 'Solo un intento de WhatsApp sin enviar puede descartarse.';

    private readonly CourseDocumentDeliveryService $deliveries;

    public function __construct(private readonly EmailService $email)
    {
        // The transport closure only belongs to the direct send path. Every
        // action of this surface queues through EmailService (email) or writes a
        // handoff the user confirms (WhatsApp), so the closure is never the
        // verdict that marks a comprobante sent.
        $this->deliveries = new CourseDocumentDeliveryService(static fn (): bool => true);
    }

    public function email(SendCommercialDocumentEmailRequest $request, CourseCommercialDocument $commercialDocument): RedirectResponse
    {
        Gate::authorize('send', CourseCommercialDocument::class);

        $edition = $this->editionOf($commercialDocument);

        try {
            $this->deliveries->queueCommercialEmail(
                $commercialDocument,
                (string) $request->validated('recipient'),
                $request->user(),
                (string) $request->validated('operation_key'),
                $this->email,
            );
        } catch (InvalidArgumentException) {
            return $this->backToListing($edition)
                ->withInput()
                ->withErrors(['commercial_document' => self::NOT_DELIVERABLE]);
        }

        return $this->backToListing($edition)
            ->with('status', 'El comprobante quedó en cola de envío por correo. La entrega se registrará cuando el envío se confirme.');
    }

    public function whatsapp(OpenCommercialWhatsAppHandoffRequest $request, CourseCommercialDocument $commercialDocument): RedirectResponse
    {
        Gate::authorize('send', CourseCommercialDocument::class);

        $edition = $this->editionOf($commercialDocument);

        try {
            $handoff = $this->deliveries->openCommercialWhatsAppHandoff(
                $commercialDocument,
                (string) $request->validated('recipient_phone'),
                $request->user(),
                (string) $request->validated('operation_key'),
            );
        } catch (InvalidArgumentException) {
            return $this->backToListing($edition)
                ->withInput()
                ->withErrors(['commercial_document' => self::NOT_DELIVERABLE]);
        }

        // Opening the handoff leaves the delivery pending: only the manual
        // confirmation below marks the comprobante sent. The prepared message
        // carries a temporary signed document link, so it is handed to the
        // browser as the WhatsApp redirect target only — never rendered, flashed
        // or logged.
        return redirect()->away($handoff['url']);
    }

    public function confirmWhatsApp(ConfirmCommercialWhatsAppSentRequest $request, CourseCommercialDocument $commercialDocument): RedirectResponse
    {
        Gate::authorize('send', CourseCommercialDocument::class);

        $edition = $this->editionOf($commercialDocument);
        $handoff = OutboundDelivery::query()->findOrFail((int) $request->validated('handoff'));

        try {
            $this->deliveries->confirmCommercialWhatsAppSent(
                $commercialDocument,
                $handoff,
                (string) $request->validated('recipient_phone'),
                $request->user(),
                (string) $request->validated('operation_key'),
            );
        } catch (InvalidArgumentException) {
            return $this->backToListing($edition)
                ->withInput()
                ->withErrors(['commercial_document' => self::CONFIRMATION_REJECTION]);
        }

        return $this->backToListing($edition)
            ->with('status', 'Entrega por WhatsApp confirmada y registrada en el historial de entregas.');
    }

    /**
     * Discard an opened handoff that was never sent. See the academic controller's twin:
     * without this, an opened handoff stayed `queued` for good, kept the confirmation
     * control offering a number the operator had already decided not to use, and left an
     * unresolved entry in the history with nothing to do about it.
     *
     * The ledger is append-only, so the attempt is skipped rather than deleted.
     */
    public function discardWhatsApp(Request $request, CourseCommercialDocument $commercialDocument): RedirectResponse
    {
        Gate::authorize('send', CourseCommercialDocument::class);

        $validated = $request->validate([
            'handoff' => ['required', 'integer', 'exists:outbound_deliveries,id'],
        ]);

        $edition = $this->editionOf($commercialDocument);
        $handoff = OutboundDelivery::query()->findOrFail((int) $validated['handoff']);

        try {
            $this->deliveries->discardCommercialWhatsAppHandoff($commercialDocument, $handoff, $request->user());
        } catch (InvalidArgumentException) {
            return $this->backToListing($edition)
                ->withErrors(['commercial_document' => self::DISCARD_REJECTION]);
        }

        return $this->backToListing($edition)
            ->with('status', 'Intento descartado. No se envió nada y el comprobante queda como estaba.');
    }

    /**
     * A comprobante belongs to the edition through exactly one target — its
     * enrollment or its enrollment group — and both paths are resolved here so no
     * other edition's screen is used as the redirect target.
     */
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
