<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Quotation creation validation (RF-COT-001). Spanish messages come from
 * lang/es/validation.php plus the per-rule overrides below.
 *
 * Invariants enforced here:
 * - exactly one of lead_id/customer_id (required_without each other + an
 *   after-callback to reject the "both set" case).
 * - At least one item.
 * - Items have valid quantity/unit_price and (when present) a tax that
 *   exists in the catalog. product_id is optional (free lines allowed).
 */
class QuotationStoreRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'rejected', 'expired', 'voided'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
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
     * Reject the "both lead_id and customer_id set" case explicitly so
     * exactly-one is enforced in addition to the required_without pair.
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
