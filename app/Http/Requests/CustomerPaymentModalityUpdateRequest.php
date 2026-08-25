<?php

namespace App\Http\Requests;

use App\Models\CustomerInvoice;
use Illuminate\Foundation\Http\FormRequest;

class CustomerPaymentModalityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [CustomerInvoice::class, $this->route('customer')]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_modality' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('payment_modality');
        $this->merge([
            'payment_modality' => is_string($value) && trim($value) !== '' ? trim($value) : null,
        ]);
    }
}
