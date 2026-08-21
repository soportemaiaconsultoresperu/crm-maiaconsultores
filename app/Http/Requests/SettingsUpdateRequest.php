<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin "settings" form (RF-CFG-004, RF-CFG-005).
 * Each setting entry carries its own `key`, `type` and `value` so the
 * service can upsert and audit independently.
 */
class SettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_\-\.]+$/i'],
            'settings.*.type' => ['required', Rule::in(SettingsService::ALLOWED_TYPES)],
            'settings.*.value' => ['present'],
            'settings.*.group' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.required' => 'Debes enviar al menos un parámetro para guardar.',
            'settings.*.type.in' => 'El tipo debe ser uno de: string, integer, decimal, boolean o json.',
            'settings.*.key.regex' => 'La clave solo puede contener letras, números, guiones y guiones bajos.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}