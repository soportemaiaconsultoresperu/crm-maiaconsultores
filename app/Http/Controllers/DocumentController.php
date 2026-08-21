<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentUploadRequest;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\Activity;
use App\Services\DocumentService;
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