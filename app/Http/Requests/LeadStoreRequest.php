<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lead creation validation (RF-LEAD-001). Spanish messages come from
 * lang/es/validation.php. Duplicate detection is NOT validation: it runs
 * separately in the UI layer with explicit confirmation (ADR-003).
 */
class LeadStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
            return [
                'person_type' => ['required', 'in:natural,juridica'],
                // first_name is required only for natural prospects — for juridica
                // the company name lives in `company_name` and the field is
                // hidden by the form, so it must be allowed to come in empty.
                'first_name' => [
                    Rule::requiredIf($this->input('person_type') === 'natural'),
                    'nullable',
                    'string',
                    'max:100',
                ],
'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'position' => ['nullable', 'string', 'max:100'],
            'doc_type' => ['nullable', 'in:dni,ruc,ce,pasaporte,otro'],
            'doc_number' => [
                'nullable',
                'max:20',
                'required_with:doc_type',
                'required_without_all:email,phone,whatsapp',
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'url', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'ubigeo_code' => ['nullable', 'digits:6', 'exists:ubigeo,code'],
            'sector' => ['nullable', 'string', 'max:100'],
            'source_id' => ['required', 'integer', 'exists:lead_sources,id'],
            'status_id' => ['required', 'integer', 'exists:lead_statuses,id'],
            'interest_level' => ['nullable', 'in:bajo,medio,alto'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'entered_at' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ];
    }

    /**
     * DNI: 8 digits; RUC: 11 digits.
     *
     * @return array<string, string|array<int, string|callable>>
     */
    public function messages(): array
    {
        return [
            'doc_number.digits' => 'El número de documento debe tener :digits dígitos para el tipo de documento seleccionado.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
