<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer update validation. Same field rules as the store request minus
 * the fields that never change (code, converted_from_lead_id,
 * converted_at); the code is ignored by CustomerService::update anyway.
 */
class CustomerUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
return [
            'person_type' => ['sometimes', 'in:natural,juridica'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'legal_name' => ['sometimes', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'position' => ['nullable', 'string', 'max:100'],
            'doc_type' => ['nullable', 'in:dni,ruc,ce,pasaporte,otro'],
            'doc_number' => array_values(array_filter([
                'nullable',
                'max:20',
                'required_with:doc_type',
                $this->docNumberDigitsRule(),
            ])),
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'url', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'fiscal_address' => ['nullable', 'string', 'max:255'],
            'ubigeo_code' => ['nullable', 'digits:6', 'exists:ubigeo,code'],
            'sector' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['sometimes', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'in:activo,inactivo'],
            'observations' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
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

    /**
     * DNI: 8 digits; RUC: 11 digits (conditional on the selected doc_type).
     */
    private function docNumberDigitsRule(): ?string
    {
        return match ($this->input('doc_type')) {
            'dni' => 'digits:8',
            'ruc' => 'digits:11',
            default => null,
        };
    }
}
