<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Quotation update validation (RF-COT-001). The number is never editable.
 * Status transitions are owned by the service, not the request; this
 * request validates only the editable payload.
 */
class QuotationUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lead_id' => [
                'nullable',
                'integer',
                'exists:leads,id',
                'required_without:customer_id',
            ],
            'customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
                'required_without:lead_id',
            ],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'opportunity_id' => ['nullable', 'integer', 'exists:opportunities,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'terms' => ['nullable', 'string'],
            'observations' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_code.size' => 'El código de moneda debe tener 3 caracteres.',
            'lead_id.required_without' => 'Debe indicar un lead o un cliente.',
            'customer_id.required_without' => 'Debe indicar un cliente o un lead.',
            'items.min' => 'La cotización debe tener al menos un ítem.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * "Exactly one of lead/customer" guard.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasLead = ! empty($this->input('lead_id'));
            $hasCustomer = ! empty($this->input('customer_id'));

            if ($hasLead && $hasCustomer) {
                $validator->errors()->add(
                    'lead_id',
                    'La cotización debe tener exactamente un lead o un cliente, no ambos.'
                );
            }
        });
    }
}
