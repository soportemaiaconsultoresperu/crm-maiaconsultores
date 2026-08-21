<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin "create team" form (B08).
 *
 * - `name` is required and capped at 100 chars (table column width).
 * - `supervisor_id` must reference an existing user.
 * - `members` is an optional list of user ids; each one is validated as
 *   existing in the users table.
 */
class TeamStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('teams', 'name')],
            'supervisor_id' => ['required', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', 'exists:users,id', 'distinct'],
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