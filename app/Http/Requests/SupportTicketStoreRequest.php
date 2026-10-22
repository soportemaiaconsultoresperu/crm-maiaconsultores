<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Services\SupportTicketService;
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
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('is_active', true)],
            'description' => ['required', 'string'],
            'impact' => ['nullable', 'string', 'max:100'],
            'general_observations' => ['nullable', 'string'],
            'responsible_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'team_id.exists' => SupportTicketService::INACTIVE_TEAM_MESSAGE,
            'responsible_id.prohibited' => 'Un ticket nuevo no debe tener responsable; use asignación para pasar a Asignado.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'customer_id' => 'cliente',
            'requester_contact_id' => 'contacto solicitante',
            'type_id' => 'tipo de ticket',
            'category_id' => 'categoría',
            'channel_id' => 'canal de ingreso',
            'priority_id' => 'prioridad',
            'team_id' => 'equipo',
            'description' => 'descripción',
            'impact' => 'impacto',
            'general_observations' => 'observaciones generales',
            'responsible_id' => 'responsable',
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
