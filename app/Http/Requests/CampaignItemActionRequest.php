<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for any state-changing action on a campaign action item:
 * start, mark realized, cancel, mark not applicable, reschedule, update metadata.
 *
 * This one request is shared by several endpoints, so the conditional rules are
 * driven by an `action` discriminator that is DERIVED FROM THE ROUTE (see
 * `prepareForValidation()`) and never read from the client. Deriving it matters:
 * the endpoints that require a field would otherwise be downgradable by simply
 * omitting the discriminator from the payload.
 *
 * `result` and the reschedule fields are the two rules the previous version was
 * missing, which is why an empty result silently completed an item and a past
 * reschedule date surfaced as an unhandled `InvalidArgumentException` (HTTP 500)
 * instead of a validation error.
 */
class CampaignItemActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is performed at the controller level (Gate::authorize).
    }

    /**
     * Expose the route's action to the rule set as a server-derived input.
     * `merge()` overwrites any client-supplied `action`, so the discriminator
     * cannot be spoofed.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['action' => $this->actionKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Marking an item realized without a result records a completed
            // touchpoint with no outcome; the flow requires the outcome.
            'result' => ['nullable', 'required_if:action,mark_realized', 'string', 'max:5000'],
            'contact_response' => ['nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'cancellation_reason' => ['nullable', 'string', 'max:1000', 'required_if:action,cancel'],
            'not_applicable_reason' => ['nullable', 'string', 'max:1000', 'required_if:action,not_applicable'],
            // Individual reschedule. The future-date rule belongs here rather
            // than only inside the service: `rescheduleIndividual()` throws a
            // plain `InvalidArgumentException` for a past date, which the HTTP
            // layer renders as a 500. Validating at the boundary turns it into
            // the 422 the caller can actually act on.
            'new_scheduled_at' => ['nullable', 'required_if:action,reschedule', 'date', 'after:now'],
            'reason' => ['nullable', 'required_if:action,reschedule', 'string', 'max:2000'],
            'next_action_at' => ['nullable', 'date'],
            'next_action_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The controller method handling this request, mapped to the `action` key
     * the rules above branch on. `null` means "no conditional rule applies".
     */
    private function actionKey(): ?string
    {
        return match ($this->route()?->getActionMethod()) {
            'markRealized' => 'mark_realized',
            'cancel' => 'cancel',
            'markNotApplicable' => 'not_applicable',
            'reschedule' => 'reschedule',
            default => null,
        };
    }
}
