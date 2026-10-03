<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input contract for the custom-field section endpoints (T5b).
 *
 * Authorization is the addressed project's `update` ability, exactly like the
 * catalog screens. Ownership and immutability are NOT re-implemented here: the
 * controller resolves every section through ContentFieldSchemaService, which
 * owns "belongs to the project" and "the slug never changes".
 */
class ManageContentFieldSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('contentProject')) ?? false;
    }

    public function rules(): array
    {
        $project = $this->route('contentProject');
        $action = last(explode('.', $this->route()->getName()));

        $rules = [];
        if (in_array($action, ['store', 'update'], true)) {
            $rules['name'] = ['required', 'string', 'max:150'];
        }
        if ($action === 'store') {
            $rules['slug'] = ['required', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_field_sections', 'slug')->where('content_project_id', $project->id)];
            $rules['sort'] = ['required', 'integer', 'min:0', 'max:2147483647'];
        }
        if ($action === 'update') {
            $rules['slug'] = ['prohibited'];
        }
        if ($action === 'state') {
            $rules['is_active'] = ['required', 'boolean'];
        }
        if ($action === 'reorder') {
            $rules['sort'] = ['required', 'integer', 'min:0', 'max:2147483647'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Indique un nombre para la sección.',
            'slug.required' => 'Indique un identificador para la sección.',
            'slug.unique' => 'Ya existe una sección con ese identificador, incluso si está desactivada.',
            'slug.regex' => 'El identificador sólo admite minúsculas, números y guiones.',
            'slug.prohibited' => 'El identificador de una sección existente no se puede cambiar.',
            'is_active.required' => 'Indique si la sección queda activa.',
        ];
    }
}
