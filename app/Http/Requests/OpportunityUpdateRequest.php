<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opportunity update validation (RF-OPP-001). Closed opportunities are
 * rejected entirely by OpportunityService (stage transitions have their
 * own dedicated flow); the code and the stage are never editable here.
 */
class OpportunityUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'contact_id' => [
                'nullable',
                'integer',
                'exists:contacts,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    // The contact must belong to the customer the
                    // opportunity is (or will be) associated with.
                    $opportunity = $this->route('opportunity');
                    $customerId = $this->integer('customer_id') ?: (int) ($opportunity->customer_id ?? 0);

                    if ($customerId === 0) {
                        $fail('El campo contacto solo puede asociarse cuando la oportunidad pertenece a un cliente.');

                        return;
                    }

                    $belongs = \App\Models\Contact::query()
                        ->where('id', $value)
                        ->where('customer_id', $customerId)
                        ->exists();

                    if (! $belongs) {
                        $fail('El contacto seleccionado no pertenece al cliente de la oportunidad.');
                    }
                },
            ],
            'owner_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'estimated_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency_code' => ['sometimes', 'required', 'string', 'size:3', 'exists:currencies,code'],
            'probability' => ['nullable', 'numeric', 'between:0,100'],
            'expected_close_at' => ['nullable', 'date'],
            'source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'priority' => ['nullable', Rule::in(['baja', 'media', 'alta'])],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_code.required' => 'El campo moneda es obligatorio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
