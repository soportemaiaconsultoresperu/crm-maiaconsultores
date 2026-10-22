<?php

namespace App\Http\Requests\InternalProjects;

use App\Models\InternalProjects\InternalProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/edit payload of an internal project phase (T2), by the team
 * (InternalProjectPolicy::manageWork).
 */
class InternalProjectPhaseRequest extends FormRequest
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'La fecha de fin de la fase no puede ser anterior a su fecha de inicio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre de la fase',
            'sort' => 'orden',
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
        ];
    }
}
