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
            'color' => ['nullable', 'string', 'max:16'],
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
        ];
    }
}
