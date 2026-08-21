<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lead update validation (RF-LEAD-001). Same field rules as store; the
 * code is immutable and catalog columns are only required when present
 * (partial updates).
 */
class LeadUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person_type' => ['sometimes', 'required', 'in:natural,juridica'],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'position' => ['nullable', 'string', 'max:100'],
            'doc_type' => ['nullable', 'in:dni,ruc,ce,pasaporte,otro'],
            'doc_number' => [
                'nullable',
                'max:20',
                Rule::when(
                    fn (): bool => $this->filled('doc_type') && $this->input('doc_type') === 'dni',
                    ['digits:8']
                ),
                Rule::when(
                    fn (): bool => $this->filled('doc_type') && $this->input('doc_type') === 'ruc',
                    ['digits:11']
                ),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'url', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'ubigeo_code' => ['nullable', 'digits:6', 'exists:ubigeo,code'],
            'sector' => ['nullable', 'string', 'max:100'],
            'source_id' => ['sometimes', 'required', 'integer', 'exists:lead_sources,id'],
            'status_id' => ['sometimes', 'required', 'integer', 'exists:lead_statuses,id'],
            'interest_level' => ['nullable', 'in:bajo,medio,alto'],
            'owner_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'entered_at' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
