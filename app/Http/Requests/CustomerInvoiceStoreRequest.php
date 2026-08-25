<?php

namespace App\Http\Requests;

use App\Models\CustomerInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerInvoiceStoreRequest extends FormRequest
{
    private const FORBIDDEN_FIELDS = [
        'payment_date', 'payment_reference', 'payment_proof', 'partial_amount',
        'partials', 'tax_amount', 'taxes', 'line_item', 'line_items', 'currency',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('create', [CustomerInvoice::class, $this->route('customer')]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->forbiddenRules(), [
            'invoice_number' => [
                'required', 'string', 'max:60',
                Rule::unique('customer_invoices', 'invoice_number')->where('customer_id', $this->route('customer')->id),
            ],
            'due_date' => ['required', 'date'],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'status_id' => ['required', 'integer', Rule::exists('invoice_statuses', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function forbiddenRules(): array
    {
        return collect(self::FORBIDDEN_FIELDS)->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])->all();
    }
}
