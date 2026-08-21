<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin "edit user" form (RF-USR-001). Password is
 * intentionally NOT editable here — the dedicated reset password flow
 * (ResetPasswordRequest + UserService::resetPassword) owns that operation
 * with its own audit event.
 */
class UserUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\User|null $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['nullable', 'string', Rule::in(['admin', 'supervisor', 'vendedor'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con este correo.',
            'role.in' => 'El rol debe ser uno de: admin, supervisor o vendedor.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}