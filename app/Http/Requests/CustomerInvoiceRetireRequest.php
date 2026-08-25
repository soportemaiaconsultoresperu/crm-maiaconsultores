<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerInvoiceRetireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('retire', $this->route('invoice')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('reason');
        $this->merge([
            'reason' => is_string($value) ? trim($value) : $value,
        ]);
    }
}
