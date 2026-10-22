<?php

namespace App\Http\Requests\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Status change of an internal project task (T2), by the team (manageWork).
 */
class InternalProjectTaskStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(TaskStatus::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'estado'];
    }
}
