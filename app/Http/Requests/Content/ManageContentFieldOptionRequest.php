<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input contract for the list-option endpoints (T5b).
 *
 * Options only exist for single/multiple selection fields; that rule belongs to
 * ContentFieldSchemaService::assertOptionsAllowed() and is applied by the
 * controller, so a non-list field is refused without duplicating the rule here.
 * The slug stays immutable after creation.
 */
class ManageContentFieldOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('contentProject')) ?? false;
    }

    public function rules(): array
    {
        $definitionId = $this->route('fieldDefinition');
        $action = last(explode('.', $this->route()->getName()));

        $rules = [];
        if (in_array($action, ['store', 'update'], true)) {
            $rules['label'] = ['required', 'string', 'max:150'];
        }
        if ($action === 'store') {
            $rules['slug'] = ['required', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_field_options', 'slug')->where('content_field_definition_id', $definitionId)];
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
            'label.required' => 'Indique una etiqueta para la opción.',
            'slug.required' => 'Indique un identificador para la opción.',
            'slug.unique' => 'Ya existe una opción con ese identificador, incluso si está retirada.',
            'slug.regex' => 'El identificador sólo admite minúsculas, números y guiones.',
            'slug.prohibited' => 'El identificador de una opción existente no se puede cambiar.',
            'is_active.required' => 'Indique si la opción queda activa.',
        ];
    }
}
