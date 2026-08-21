<?php

namespace App\Http\Requests;

use App\Models\CampaignActionItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for any state-changing action on a campaign action item:
 * start, mark realized, cancel, mark not applicable, update metadata.
 */
class CampaignItemActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is performed at the controller level (Gate::authorize).
    }

    public function rules(): array
    {
        return [
            'result' => ['nullable', 'string', 'max:5000'],
            'contact_response' => ['nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'cancellation_reason' => ['nullable', 'string', 'max:1000', 'required_if:action,cancel'],
            'not_applicable_reason' => ['nullable', 'string', 'max:1000', 'required_if:action,not_applicable'],
            'next_action_at' => ['nullable', 'date'],
            'next_action_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
