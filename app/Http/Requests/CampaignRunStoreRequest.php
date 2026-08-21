<?php

namespace App\Http\Requests;

use App\Models\CampaignRun;
use Illuminate\Foundation\Http\FormRequest;

class CampaignRunStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CampaignRun::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', 'exists:campaign_templates,id'],
            'name' => ['required', 'string', 'max:200'],
            'starts_at' => ['required', 'date'],
            'ends_at_estimated' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'observations' => ['nullable', 'string', 'max:1000'],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*.subject_type' => ['required', 'in:lead,customer,contact,opportunity'],
            'participants.*.subject_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
