<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Automations;

use App\Enums\AutomationMode;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B12-UI — PR 3 / Stage 3A — UpdateRuleRequest payload contract (CRUD-03).
 *
 * Same payload contract as StoreRuleRequest; the controller replaces the
 * condition_groups + actions in a single transaction after validation passes.
 */
class UpdateRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'trigger_event' => ['required', 'string', 'max:191', Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'mode' => ['sometimes', Rule::in(AutomationMode::values())],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'groups' => ['sometimes', 'array'],
            'groups.*.logical_operator' => ['required_with:groups', Rule::in(['AND', 'OR'])],
            'groups.*.position' => ['required_with:groups', 'integer', 'min:0'],
            'groups.*.conditions' => ['sometimes', 'array'],
            'groups.*.conditions.*.field' => ['required_with:groups.*.conditions', 'string', 'max:191'],
            'groups.*.conditions.*.operator' => ['required_with:groups.*.conditions', 'string', 'max:32'],
            'groups.*.conditions.*.value' => ['nullable'],
            'groups.*.conditions.*.value_type' => ['nullable', Rule::in(['string', 'int', 'bool', 'date', 'datetime', 'enum', 'array'])],
            'groups.*.conditions.*.position' => ['required_with:groups.*.conditions', 'integer', 'min:0'],
            'actions' => ['sometimes', 'array'],
            'actions.*.type' => ['required_with:actions', 'string', 'max:80'],
            'actions.*.position' => ['required_with:actions', 'integer', 'min:0'],
            'actions.*.channel' => ['nullable', 'string', 'max:40'],
            'actions.*.recipient_strategy' => ['nullable', 'string', 'max:80'],
            'actions.*.payload_json' => ['nullable'],
            'actions.*.retry_policy_json' => ['nullable'],
            'actions.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
