<?php

namespace App\Http\Requests;

use App\Models\InternalActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'audience_type' => ['required', Rule::in(array_keys(InternalActivity::AUDIENCE_LABELS))],
            'role_ids' => ['required_if:audience_type,'.InternalActivity::AUDIENCE_ROLES, 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'user_ids' => ['required_if:audience_type,'.InternalActivity::AUDIENCE_USERS, 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'starts_at' => 'inicio',
            'ends_at' => 'fin',
            'audience_type' => 'audiencia',
            'role_ids' => 'roles',
            'user_ids' => 'personas',
            'participant_ids' => 'participantes',
            'responsible_id' => 'responsable',
        ];
    }
}
