<?php

namespace App\Http\Requests;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportTicketStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('support.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'requester_contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'type_id' => ['required', 'integer', Rule::exists('support_ticket_types', 'id')->where('is_active', true)],
            'category_id' => ['required', 'integer', Rule::exists('support_categories', 'id')->where('is_active', true)],
            'channel_id' => ['required', 'integer', Rule::exists('support_channels', 'id')->where('is_active', true)],
            'priority_id' => ['required', 'integer', Rule::exists('support_priorities', 'id')->where('is_active', true)],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'description' => ['required', 'string'],
            'impact' => ['nullable', 'string', 'max:100'],
            'general_observations' => ['nullable', 'string'],
            'responsible_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $customerId = (int) $this->input('customer_id');
            $contactId = (int) $this->input('requester_contact_id');

            if ($customerId <= 0 || $contactId <= 0) {
                return;
            }

            $belongs = Contact::query()->whereKey($contactId)->where('customer_id', $customerId)->exists();

            if (! $belongs) {
                $validator->errors()->add('requester_contact_id', 'El contacto solicitante no pertenece al cliente seleccionado.');
            }
        }];
    }
}
