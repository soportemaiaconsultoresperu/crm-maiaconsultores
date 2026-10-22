<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Descriptive fields only (SupportTicketService::EDITABLE_FIELDS); status, customer, priority and responsible have their own flows. */
class SupportTicketUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && (bool) $this->user()?->can('update', $ticket);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'impact' => ['nullable', 'string', 'max:100'],
            'general_observations' => ['nullable', 'string'],
            'type_id' => ['required', 'integer', Rule::exists('support_ticket_types', 'id')->where('is_active', true)],
            'category_id' => ['required', 'integer', Rule::exists('support_categories', 'id')->where('is_active', true)],
            'channel_id' => ['required', 'integer', Rule::exists('support_channels', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'title.max' => 'El título no debe superar los 200 caracteres.',
            'description.required' => 'La descripción es obligatoria.',
            'impact.max' => 'El impacto no debe superar los 100 caracteres.',
            'type_id.required' => 'Seleccione el tipo de ticket.',
            'type_id.exists' => 'El tipo de ticket no está activo.',
            'category_id.required' => 'Seleccione la categoría.',
            'category_id.exists' => 'La categoría de soporte no está activa.',
            'channel_id.required' => 'Seleccione el canal de ingreso.',
            'channel_id.exists' => 'El canal de ingreso no está activo.',
        ];
    }
}
