<?php

namespace App\Http\Requests;

use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignTemplateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CampaignTemplate::class) ?? false;
    }

    public function rules(): array
    {
        // On update the route param `template` is the model itself; on store
        // it's null and ignore(null) means "validate against every row".
        $templateId = $this->route('template')?->id;

        return [
            'name' => ['required', 'string', 'max:200', Rule::unique('campaign_templates', 'name')->ignore($templateId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'objective' => ['nullable', Rule::in(CampaignTemplate::OBJECTIVES)],
            'status' => ['nullable', Rule::in([
                CampaignTemplate::STATUS_DRAFT, CampaignTemplate::STATUS_ACTIVE, CampaignTemplate::STATUS_INACTIVE,
            ])],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.action_type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'steps.*.title' => ['required', 'string', 'max:200'],
            'steps.*.day_offset' => ['nullable', 'integer', 'min:0', 'max:365'],
            'steps.*.scheduled_time' => ['nullable', 'date_format:H:i'],
            'steps.*.instructions' => ['nullable', 'string', 'max:2000'],
            'steps.*.is_required' => ['nullable', 'boolean'],
            'steps.*.is_advertising' => ['nullable', 'boolean'],
            'steps.*.order' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
