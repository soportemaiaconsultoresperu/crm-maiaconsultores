<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the admin "reset password" form (RF-USR-005). The new
 * password must be at least 8 characters and is required (no random
 * fallback: an admin reset is always explicit).
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}