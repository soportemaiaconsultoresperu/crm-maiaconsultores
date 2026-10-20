<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edition payload for a content project (T3).
 *
 * Same fields as creation. The slug uniqueness rule ignores the current record
 * so saving the form without touching the identifier succeeds, while a slug
 * already taken by another project is still refused.
 *
 * Like the create request, authorization stays in the controller: the
 * controller resolves the project through ContentProjectScopeService and
 * reports a project the user may not reach as 404 (not 403) before asking the
 * policy for the `configure` ability.
 */
class UpdateContentProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same colour picker normalization as StoreContentProjectRequest.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('color')) {
            return;
        }

        $color = $this->input('color');

        if ($color === 'custom') {
            $color = $this->input('color_custom');
        }

        if (is_string($color)) {
            $color = trim($color) === '' ? null : strtolower(trim($color));
        }

        $this->merge(['color' => $color]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $projectId = $this->route('contentProject')?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_projects', 'slug')->ignore($projectId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indique un nombre para el proyecto.',
            'slug.required' => 'Indique un identificador para el proyecto.',
            'slug.regex' => 'El identificador sólo admite minúsculas, números y guiones (ej. maia-consultores).',
            'slug.unique' => 'Ya existe un proyecto con ese identificador.',
            'color.string' => 'Elija un color válido en formato hexadecimal (ej. #1d4ed8).',
            'color.regex' => 'Elija un color válido en formato hexadecimal (ej. #1d4ed8).',
        ];
    }
}
