<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contact creation validation (RF-CON-001). Spanish messages come from
 * lang/es/validation.php.
 *
 * Two flows reach this request:
 *   - in-ficha   (customers.contacts.store): the route binding `{customer}`
 *                owns the customer context; the form never sends customer_id.
 *                The controller overwrites $data['customer_id'] with the
 *                bound model after validation, so customer_id must NOT be
 *                required here, otherwise the in-ficha modal silently fails.
 *   - standalone (contacts.store): the form sends customer_id from a select.
 *
 * `customer_id` is therefore required ONLY when the request is not bound to
 * a `{customer}` route, and validated against `exists:customers,id` in both
 * flows when present.
 */
class ContactStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                Rule::requiredIf(fn (): bool => $this->route('customer') === null),
                'nullable',
                'integer',
                'exists:customers,id',
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_primary' => ['nullable', 'boolean'],
            'observations' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
