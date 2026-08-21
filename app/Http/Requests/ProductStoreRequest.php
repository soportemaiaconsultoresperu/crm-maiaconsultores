<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Product creation validation (RF-PROD-001). Spanish messages come from
 * lang/es/validation.php plus the per-rule overrides below.
 */
class ProductStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20', 'unique:products,code'],
            'product_type' => ['required', 'in:producto,servicio'],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_type.in' => 'El tipo de producto debe ser "producto" o "servicio".',
            'currency_code.size' => 'El código de moneda debe tener 3 caracteres.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
