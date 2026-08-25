<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentUploadRequest;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\Activity;
use App\Models\SupportIncidentDetail;
use App\Models\SupportObservation;
use App\Models\SupportSessionDetail;
use App\Models\SupportTicket;
use App\Services\DocumentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * B09 / RF-DOC-001..005 — Documents UI layer.
 *
 * The controller stays thin: validation lives in DocumentUploadRequest,
 * authorization lives in DocumentPolicy / permission gates, and the
 * storage lifecycle lives in DocumentService.
 *
 * Routes (see routes/web.php):
 *   POST   leads/{lead}/documents            upload under a Lead
 *   POST   customers/{customer}/documents    upload under a Customer
 *   POST   contacts/{contact}/documents      upload under a Contact
 *   POST   opportunities/{opp}/documents     upload under an Opportunity
 *   POST   quotations/{quotation}/documents  upload under a Quotation
 *   POST   activities/{activity}/documents   upload under an Activity
 *   GET    documents/{document}/download     authorized download
 *   DELETE documents/{document}              authorized hard delete
 */
class DocumentController extends Controller
{
    /**
     * Standalone documents module. Embedded panels remain the primary upload
     * surface; this index makes the sidebar module useful by listing the latest
     * documents the actor is allowed to see or manage.
     */
    public function index(Request $request, DocumentService $service): View
    {
        $user = $request->user();

        abort_unless(
            $user->can('documents.download') || $user->can('documents.view.any') || $user->can('documents.upload'),
            403,
        );

        $documents = Document::query()
            ->with(['uploader', 'docable'])
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(fn (Document $document): bool => $user->can('documents.view.any')
                || $service->canDownload($document, $user)
                || (int) $document->uploaded_by === (int) $user->id)
            ->values();

        return view('documents.index', ['documents' => $documents]);
    }

    /**
     * Polymorphic upload endpoint. The subject type is determined from the
     * controller action invoked by the route, so each `storeFor*` method
     * resolves the right morph subject before delegating to the service.
     */
    public function storeForLead(DocumentUploadRequest $request, Lead $lead, DocumentService $service): RedirectResponse
    {
        $this->authorizeOn($lead);

        $document = $service->upload($lead, $request->file('file'), $request->user());

        return redirect()
            ->route('leads.show', $lead)
            ->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForCustomer(DocumentUploadRequest $request, Customer $customer, DocumentService $service): RedirectResponse
    {
        $this->authorizeOn($customer);

        $document = $service->upload($customer, $request->file('file'), $request->user());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForContact(DocumentUploadRequest $request, \App\Models\Contact $contact, DocumentService $service): RedirectResponse
    {
        // Contacts inherit visibility from their customer; gate on view.
        Gate::authorize('view', $contact);

        $document = $service->upload($contact, $request->file('file'), $request->user());

        return redirect()
            ->route('customers.show', $contact->customer)
            ->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForOpportunity(DocumentUploadRequest $request, Opportunity $opportunity, DocumentService $service): RedirectResponse
    {
        $this->authorizeOn($opportunity);

        $document = $service->upload($opportunity, $request->file('file'), $request->user());

        return redirect()
            ->route('opportunities.show', $opportunity)
            ->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForQuotation(DocumentUploadRequest $request, Quotation $quotation, DocumentService $service): RedirectResponse
    {
        $this->authorizeOn($quotation);

        $document = $service->upload($quotation, $request->file('file'), $request->user());

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForActivity(DocumentUploadRequest $request, Activity $activity, DocumentService $service): RedirectResponse
    {
        $this->authorizeOn($activity);

        $document = $service->upload($activity, $request->file('file'), $request->user());

        return redirect()
            ->route('activities.show', $activity)
            ->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForSupportTicket(DocumentUploadRequest $request, SupportTicket $ticket, DocumentService $service): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        Gate::authorize('create', Document::class);
        $document = $service->upload($ticket, $request->file('file'), $request->user());
        return redirect()->route('support.tickets.show', $ticket)->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForSupportObservation(DocumentUploadRequest $request, SupportTicket $ticket, SupportObservation $observation, DocumentService $service): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        Gate::authorize('create', Document::class);
        abort_unless((int) $observation->ticket_id === (int) $ticket->id, 404);
        $document = $service->upload($observation, $request->file('file'), $request->user());
        return redirect()->route('support.tickets.show', $ticket)->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForSupportIncident(DocumentUploadRequest $request, SupportTicket $ticket, SupportIncidentDetail $incident, DocumentService $service): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        Gate::authorize('create', Document::class);
        abort_unless((int) $incident->ticket_id === (int) $ticket->id, 404);
        $document = $service->upload($incident, $request->file('file'), $request->user());
        return redirect()->route('support.tickets.show', $ticket)->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    public function storeForSupportSession(DocumentUploadRequest $request, SupportTicket $ticket, SupportSessionDetail $session, DocumentService $service): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        Gate::authorize('create', Document::class);
        abort_unless((int) $session->ticket_id === (int) $ticket->id, 404);
        $document = $service->upload($session, $request->file('file'), $request->user());
        return redirect()->route('support.tickets.show', $ticket)->with('status', "Documento \"{$document->name}\" subido correctamente.");
    }

    /**
     * Authorized download (ADR-011). Always routed through this controller
     * so the audit log captures the IP and the actor.
     */
    public function download(Request $request, Document $document, DocumentService $service): StreamedResponse
    {
        return $service->download($document, $request->user());
    }

    /**
     * Hard delete — physical file + DB row (RF-DOC-005).
     */
    public function destroy(Request $request, Document $document, DocumentService $service): RedirectResponse
    {
        $service->delete($document, $request->user());

        return redirect()->back()->with('status', 'Documento eliminado correctamente.');
    }

    /**
     * The HTTP layer enforces "may see the subject" before allowing the
     * upload. The download/visibility check on the document itself is
     * enforced inside DocumentService::canDownload.
     */
    private function authorizeOn(\Illuminate\Database\Eloquent\Model $subject): void
    {
        Gate::authorize('view', $subject);
    }
}