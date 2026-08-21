<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Product update validation (RF-PROD-001). The code is never editable;
 * uniqueness must ignore the current record.
 */
class ProductUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'code' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('products', 'code')->ignore($productId),
            ],
            'product_type' => ['sometimes', 'in:producto,servicio'],
            'name' => ['sometimes', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'exists:currencies,code'],
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
