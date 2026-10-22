<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creation payload for a content project (T3).
 *
 * Only what the create form can express: the project's identity (name, slug,
 * description, colour) and its initial active flag. Membership is not part of
 * this payload — it is a separate, explicitly authorized action
 * (`content.projects.members.sync`) — so a tampered `members` key here is
 * simply not validated and never reaches the model.
 *
 * The slug is the project's stable identifier, so it is constrained to the
 * lowercase dashed form the rest of the CRM uses and made unique. The unique
 * rule matches the database index on purpose: a soft-deleted project still
 * occupies its slug until it is restored or purged.
 */
class StoreContentProjectRequest extends FormRequest
{
    /**
     * The module permission is checked here as well as in the controller, so an
     * unauthorized submit is refused before its payload is validated.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Content\ContentProject::class) ?? false;
    }

    /**
     * The colour picker submits a preset hex, an empty "Sin color" or
     * `custom` together with the native colour input (`color_custom`). The
     * chosen value is trimmed and lowercased so the stored hex is canonical.
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_projects', 'slug'),
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
