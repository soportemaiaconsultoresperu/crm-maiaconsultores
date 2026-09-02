<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lead update validation. Legal prospects keep their identity and primary
 * contact separate from the prospect row itself.
 */
class LeadUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isLegalPerson = $this->input('person_type') === 'juridica';
        $isNaturalPerson = $this->input('person_type') === 'natural';

        return [
            'person_type' => ['sometimes', 'required', 'in:natural,juridica'],
            'first_name' => [
                Rule::requiredIf($this->input('person_type') === 'natural'),
                'nullable',
                'string',
                'max:100',
            ],
            'last_name' => ['nullable', 'string', 'max:100'],
            'legal_name' => [Rule::requiredIf($isLegalPerson), 'nullable', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'position' => ['nullable', 'string', 'max:100'],
            'doc_type' => ['nullable', 'in:dni,ruc,ce,pasaporte,otro', 'required_with:doc_number'],
            'doc_number' => [
                'nullable',
                'max:20',
                Rule::when($this->input('doc_type') === 'dni', ['digits:8']),
                Rule::when($this->input('doc_type') === 'ruc', ['digits:11']),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                Rule::requiredIf($isNaturalPerson && ! $this->filled('whatsapp') && ! $this->filled('email')),
            ],
            'whatsapp' => [
                'nullable',
                'string',
                'max:30',
                Rule::requiredIf($isNaturalPerson && ! $this->filled('phone') && ! $this->filled('email')),
            ],
            'email' => [
                'nullable',
                'email',
                'max:150',
                Rule::requiredIf($isNaturalPerson && ! $this->filled('phone') && ! $this->filled('whatsapp')),
            ],
            'primary_contact' => [Rule::requiredIf($isLegalPerson), 'nullable', 'array'],
            'primary_contact.first_name' => [Rule::when($isLegalPerson, ['required', 'string', 'max:100'])],
            'primary_contact.last_name' => [Rule::when($isLegalPerson, ['required', 'string', 'max:100'])],
            'primary_contact.position' => [Rule::when($isLegalPerson, ['nullable', 'string', 'max:100'])],
            'primary_contact.phone' => [Rule::when($isLegalPerson, ['nullable', 'string', 'max:30', 'required_without_all:primary_contact.whatsapp,primary_contact.email'])],
            'primary_contact.whatsapp' => [Rule::when($isLegalPerson, ['nullable', 'string', 'max:30', 'required_without_all:primary_contact.phone,primary_contact.email'])],
            'primary_contact.email' => [Rule::when($isLegalPerson, ['nullable', 'email', 'max:150', 'required_without_all:primary_contact.phone,primary_contact.whatsapp'])],
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
