<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Email;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B13 Pasada B — Validation contract for creating an {@see \App\Models\Email\EmailTemplate}.
 *
 * Permission gate (`email.template.manage`) is the canonical Spatie pattern.
 * The variable list entries must be lowercase snake_case identifiers per
 *   docs/v2/01-roadmap.md §6 decision 11c.
 */
class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('email.template.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/', 'unique:email_templates,slug'],
            'subject' => ['required', 'string', 'max:191'],
            'body_html' => ['required', 'string'],
            'body_text' => ['required', 'string'],
            'variables_json' => ['sometimes', 'array'],
            'variables_json.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', Rule::in($this->allowedVariableNames())],
            'is_active' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedVariableNames(): array
    {
        $vars = $this->input('variables_json', []);
        if (! is_array($vars)) {
            return [];
        }

        return array_values(array_filter($vars, 'is_string'));
    }
}
