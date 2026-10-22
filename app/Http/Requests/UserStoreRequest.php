<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin "create user" form (RF-USR-001).
 *
 * The password is optional here: when omitted, UserService generates a
 * random one and surfaces it back to the controller exactly once. The
 * email is unique across the table (Laravel's case-insensitive collation
 * handles the rest). Assignable roles must exist for the web guard.
 */
class UserStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con este correo.',
            'role.exists' => 'El rol seleccionado no existe para usuarios web.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}