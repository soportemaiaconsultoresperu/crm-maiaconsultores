<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opportunity creation validation (RF-OPP-001, docs §3.4). Spanish
 * messages come from lang/es/validation.php. The exactly-one-of
 * lead/customer invariant is re-checked in OpportunityService.
 */
class OpportunityStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'lead_id' => [
                'nullable',
                'integer',
                'exists:leads,id',
                'required_without:customer_id',
                'prohibited_unless:customer_id,null',
            ],
            'customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
                'required_without:lead_id',
                'prohibited_unless:lead_id,null',
            ],
            'contact_id' => [
                'nullable',
                'integer',
                'exists:contacts,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $customerId = $this->integer('customer_id');

                    if ($customerId === 0) {
                        $fail('El campo contacto solo puede asociarse cuando se selecciona un cliente.');

                        return;
                    }

                    $belongs = \App\Models\Contact::query()
                        ->where('id', $value)
                        ->where('customer_id', $customerId)
                        ->exists();

                    if (! $belongs) {
                        $fail('El contacto seleccionado no pertenece al cliente indicado.');
                    }
                },
            ],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'estimated_amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
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
            'lead_id.required_without' => 'Debe indicar un lead o un cliente para la oportunidad.',
            'customer_id.required_without' => 'Debe indicar un cliente o un lead para la oportunidad.',
            'lead_id.prohibited_unless' => 'La oportunidad solo puede estar asociada a un lead o a un cliente, no a ambos.',
            'customer_id.prohibited_unless' => 'La oportunidad solo puede estar asociada a un cliente o a un lead, no a ambos.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
