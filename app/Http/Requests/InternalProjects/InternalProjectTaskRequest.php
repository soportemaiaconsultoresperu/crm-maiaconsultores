<?php

namespace App\Http\Requests\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/edit payload of an internal project task (T2). The phase must belong
 * to the route project; InternalProjectTaskService re-checks every domain
 * invariant. Only the team writes (manageWork).
 */
class InternalProjectTaskRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller so a viewer is refused
     * before the payload is validated.
     */
    public function authorize(): bool
    {
        $project = $this->route('internalProject');

        return $project instanceof InternalProject && ($this->user()?->can('manageWork', $project) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_milestone' => $this->boolean('is_milestone')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('internalProject');

        return [
            'internal_project_phase_id' => ['required', 'integer',
                Rule::exists('internal_project_phases', 'id')
                    ->where('internal_project_id', $project instanceof InternalProject ? $project->id : 0)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'starts_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'weight' => ['required', 'integer', 'min:1', 'max:65535'],
            'is_milestone' => ['boolean'],
            'status' => ['nullable', Rule::in(TaskStatus::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'internal_project_phase_id.exists' => 'La fase seleccionada no pertenece a este proyecto.',
            'due_on.after_or_equal' => 'La fecha de vencimiento no puede ser anterior a la fecha de inicio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'internal_project_phase_id' => 'fase',
            'title' => 'título',
            'description' => 'descripción',
            'responsible_user_id' => 'responsable',
            'starts_on' => 'fecha de inicio',
            'due_on' => 'fecha de vencimiento',
            'weight' => 'peso',
            'is_milestone' => 'hito',
            'status' => 'estado',
        ];
    }
}
