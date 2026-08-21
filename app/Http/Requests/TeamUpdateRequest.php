<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin "edit team" form (B08). Membership changes
 * go through the dedicated addMember / removeMember endpoints, so this
 * request only handles name, supervisor and active flag.
 */
class TeamUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\Team|null $team */
        $team = $this->route('team');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('teams', 'name')->ignore($team?->getKey()),
            ],
            'supervisor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un equipo con este nombre.',
            'supervisor_id.exists' => 'El supervisor seleccionado no existe.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}