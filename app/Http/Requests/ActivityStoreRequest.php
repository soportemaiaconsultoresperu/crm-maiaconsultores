<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activity creation validation (RF-ACT-001..006). Spanish messages come
 * from lang/es/validation.php; this class only declares the rules and a
 * few targeted messages for length / status / priority fields.
 *
 * Scheduling / next-action enforcement (RF-ACT-005, ADR-012) happens in
 * ActivityService — this FormRequest only validates inputs.
 */
class ActivityStoreRequest extends FormRequest
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
            'subject_type' => ['required', 'in:'.implode(',', Activity::SUBJECT_TYPES)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'subject_id_lead' => ['nullable', 'integer', 'min:1'],
            'subject_id_customer' => ['nullable', 'integer', 'min:1'],
            'subject_id_opportunity' => ['nullable', 'integer', 'min:1'],
            'type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,in_process'],
            'priority' => ['nullable', 'in:baja,media,alta'],
            'reminder_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_type.in' => 'El tipo de sujeto debe ser prospecto, cliente u oportunidad.',
            'subject_id.required' => 'El sujeto de la actividad es obligatorio.',
            'type_id.exists' => 'El tipo de actividad seleccionado no existe.',
            'title.required' => 'El título de la actividad es obligatorio.',
            'title.max' => 'El título no debe exceder los :max caracteres.',
            'priority.in' => 'La prioridad debe ser baja, media o alta.',
            'status.in' => 'El estado inicial debe ser pendiente o en proceso.',
        ];
    }

    public function attributes(): array
    {
        return [
            'subject_type' => 'tipo de sujeto',
            'subject_id' => 'sujeto',
            'type_id' => 'tipo de actividad',
            'owner_id' => 'responsable',
            'title' => 'título',
            'description' => 'descripción',
            'scheduled_at' => 'fecha programada',
            'status' => 'estado',
            'priority' => 'prioridad',
            'reminder_at' => 'recordatorio',
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