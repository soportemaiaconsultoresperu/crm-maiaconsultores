<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Email;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B13 Pasada B — Validation contract for updating an {@see \App\Models\Email\EmailTemplate}.
 */
class UpdateTemplateRequest extends FormRequest
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
        $templateId = $this->route('template')?->id ?? $this->input('template_id');

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('email_templates', 'slug')->ignore($templateId),
            ],
            'subject' => ['required', 'string', 'max:191'],
            'body_html' => ['required', 'string'],
            'body_text' => ['required', 'string'],
            'variables_json' => ['sometimes', 'array'],
            'variables_json.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            'is_active' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'template_id' => ['sometimes', 'integer'],
        ];
    }
}
