<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activity update validation (RF-ACT-001..006). The state-machine rules
 * (no updates on completed/cancelled) are enforced by ActivityService.
 *
 * `next_*` fields are validated here but consumed only when status is
 * `completed` in the service; supplying them on a plain update is a
 * client-side UX hint, never a validation error.
 */
class ActivityUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('subject_id') && ($fallback = $this->selectedPaneSubjectId()) !== null) {
            $this->merge(['subject_id' => $fallback]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['nullable', 'in:'.implode(',', Activity::SUBJECT_TYPES)],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'subject_id_lead' => ['nullable', 'integer', 'min:1'],
            'subject_id_customer' => ['nullable', 'integer', 'min:1'],
            'subject_id_opportunity' => ['nullable', 'integer', 'min:1'],
            'type_id' => ['nullable', 'integer', 'exists:activity_types,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,in_process,completed,cancelled'],
            'priority' => ['nullable', 'in:baja,media,alta'],
            'reminder_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string', 'max:255'],
            'executed_at' => ['nullable', 'date'],

            // RF-ACT-005: optional follow-up.
            'next_scheduled_at' => ['nullable', 'date'],
            'next_type_id' => ['nullable', 'integer', 'exists:activity_types,id'],
            'next_title' => ['nullable', 'string', 'max:200'],
            'next_owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_type.in' => 'El tipo de sujeto debe ser prospecto, cliente, oportunidad o contacto.',
            'type_id.exists' => 'El tipo de actividad seleccionado no existe.',
            'title.max' => 'El título no debe exceder los :max caracteres.',
            'priority.in' => 'La prioridad debe ser baja, media o alta.',
            'result.max' => 'El resultado no debe exceder los :max caracteres.',
            'next_type_id.exists' => 'El tipo de seguimiento seleccionado no existe.',
            'next_title.max' => 'El título del seguimiento no debe exceder los :max caracteres.',
        ];
    }

    public function attributes(): array
    {
        return [
            'type_id' => 'tipo de actividad',
            'owner_id' => 'responsable',
            'title' => 'título',
            'description' => 'descripción',
            'scheduled_at' => 'fecha programada',
            'status' => 'estado',
            'priority' => 'prioridad',
            'reminder_at' => 'recordatorio',
            'result' => 'resultado',
            'executed_at' => 'fecha de ejecución',
            'next_scheduled_at' => 'fecha del siguiente seguimiento',
            'next_type_id' => 'tipo del siguiente seguimiento',
            'next_title' => 'título del siguiente seguimiento',
            'next_owner_id' => 'responsable del siguiente seguimiento',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    private function selectedPaneSubjectId(): ?string
    {
        $subjectType = $this->input('subject_type');
        if (! is_string($subjectType) || ! in_array($subjectType, ['lead', 'customer', 'opportunity'], true)) {
            return null;
        }

        $value = $this->input('subject_id_'.$subjectType);

        return filled($value) ? (string) $value : null;
    }
}