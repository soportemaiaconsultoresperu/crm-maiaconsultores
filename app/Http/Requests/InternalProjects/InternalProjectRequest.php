<?php

namespace App\Http\Requests\InternalProjects;

use App\Enums\InternalProjects\ProjectStatus;
use App\Models\InternalProjects\InternalProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/edit payload of an internal project (T2): identity, planned dates,
 * status, responsible, team members and audience (same model as internal
 * activities).
 */
class InternalProjectRequest extends FormRequest
{
    /**
     * Checked here as well as in the controller so an unauthorized submit is
     * refused before the payload is validated.
     */
    public function authorize(): bool
    {
        $project = $this->route('internalProject');

        return $project instanceof InternalProject
            ? ($this->user()?->can('update', $project) ?? false)
            : ($this->user()?->can('create', InternalProject::class) ?? false);
    }

    /**
     * Accept an active user, or one already assigned to this project.
     *
     * @param  list<int>  $assignedIds
     */
    private function activeOrAssigned(array $assignedIds): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($assignedIds): void {
            $id = (int) $value;
            if (in_array($id, $assignedIds, true)) {
                return;
            }
            if (! \App\Models\User::query()->whereKey($id)->where('is_active', true)->exists()) {
                $fail('El usuario seleccionado no existe o está inactivo.');
            }
        };
    }

    /**
     * The colour picker submits a preset hex, an empty "Sin color" or
     * `custom` together with the native colour input (`color_custom`).
     */
    protected function prepareForValidation(): void
    {
        $merge = ['code' => is_string($this->input('code')) ? trim($this->input('code')) : $this->input('code')];

        if ($this->has('color')) {
            $color = $this->input('color');
            if ($color === 'custom') {
                $color = $this->input('color_custom');
            }
            if (is_string($color)) {
                $color = trim($color) === '' ? null : strtolower(trim($color));
            }
            $merge['color'] = $color;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('internalProject');
        $current = $project instanceof InternalProject ? $project : null;

        // New people must be active users; someone already assigned stays valid
        // even if deactivated meanwhile, so editing never drops them silently.
        $responsibleIds = $current?->responsible_user_id ? [(int) $current->responsible_user_id] : [];
        $memberIds = $current ? $current->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all() : [];
        $audienceIds = $current ? $current->audienceUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all() : [];

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('internal_projects', 'code')->ignore($project instanceof InternalProject ? $project->id : null)],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['required', Rule::in(ProjectStatus::values())],
            'responsible_user_id' => ['nullable', 'integer', $this->activeOrAssigned($responsibleIds)],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', $this->activeOrAssigned(array_merge($memberIds, $responsibleIds))],
            'audience_type' => ['required', Rule::in(array_keys(InternalProject::AUDIENCE_LABELS))],
            'role_ids' => ['required_if:audience_type,'.InternalProject::AUDIENCE_ROLES, 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'user_ids' => ['required_if:audience_type,'.InternalProject::AUDIENCE_USERS, 'array'],
            'user_ids.*' => ['integer', $this->activeOrAssigned($audienceIds)],
            'create_default_phases' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un proyecto con este código.',
            'code.regex' => 'El código solo puede tener letras, números, puntos, guiones y guiones bajos.',
            'color.regex' => 'Elija un color válido.',
            'ends_on.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
            'role_ids.required_if' => 'Seleccione al menos un rol.',
            'user_ids.required_if' => 'Seleccione al menos una persona.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'code' => 'código',
            'description' => 'descripción',
            'color' => 'color',
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'status' => 'estado',
            'responsible_user_id' => 'responsable',
            'member_ids' => 'equipo',
            'audience_type' => 'audiencia',
            'role_ids' => 'roles',
            'user_ids' => 'personas',
        ];
    }
}
