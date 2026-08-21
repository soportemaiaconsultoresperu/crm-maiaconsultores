<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CampaignRescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is performed at the controller level (Gate::authorize).
    }

    public function rules(): array
    {
        return [
            'new_scheduled_at' => ['nullable', 'date'],
            'new_starts_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'strategy' => ['nullable', 'array'],
            'strategy.*' => ['nullable', 'in:recalc,preserve'],
        ];
    }
}
