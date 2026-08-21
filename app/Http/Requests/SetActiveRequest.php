<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the activate/deactivate toggle (RF-USR-001).
 *
 * The "cannot deactivate self" guard is enforced defensively inside
 * UserService::setActive; this request only validates the shape of the
 * payload. The reason field is optional on activation but required when
 * deactivating, matching the conventions of LeadService::deactivate and
 * CustomerService::deactivate.
 *
 * @return array<string, mixed>
 */
class SetActiveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:is_active,0'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required_if' => 'Indica un motivo para desactivar al usuario.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}