<?php

namespace App\Http\Requests\Content;

use App\Enums\Content\ContentFieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input contract for the custom-field definition endpoints (T5b).
 *
 * The `key` and the `type` are deliberately absent from the update payload:
 * the stored key is derived once from the label and the type is fixed after
 * creation, so neither is offered as an editable control. Section ownership and
 * key/type immutability are enforced by ContentFieldSchemaService, not here.
 */
class ManageContentFieldDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configure', $this->route('contentProject')) ?? false;
    }

    public function rules(): array
    {
        $action = last(explode('.', $this->route()->getName()));

        $rules = [];
        if ($action === 'store') {
            $rules['label'] = ['required', 'string', 'max:150'];
            $rules['type'] = ['required', Rule::in(ContentFieldType::values())];
            $rules['content_field_section_id'] = ['nullable', 'integer'];
            $rules['required'] = ['sometimes', 'boolean'];
            $rules['help_text'] = ['nullable', 'string', 'max:2000'];
            $rules['sort'] = ['required', 'integer', 'min:0', 'max:2147483647'];
        }
        if ($action === 'update') {
            $rules['label'] = ['required', 'string', 'max:150'];
            $rules['content_field_section_id'] = ['nullable', 'integer'];
            $rules['required'] = ['sometimes', 'boolean'];
            $rules['help_text'] = ['nullable', 'string', 'max:2000'];
            $rules['key'] = ['prohibited'];
            $rules['type'] = ['prohibited'];
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
            'label.required' => 'Indique una etiqueta para el campo.',
            'type.required' => 'Seleccione el tipo del campo.',
            'type.in' => 'El tipo seleccionado no está soportado.',
            'type.prohibited' => 'El tipo de un campo no se puede cambiar una vez creado.',
            'key.prohibited' => 'La clave de un campo no se puede cambiar una vez creada.',
            'content_field_section_id.integer' => 'La sección seleccionada no es válida.',
            'help_text.max' => 'El texto de ayuda no puede superar los 2000 caracteres.',
            'is_active.required' => 'Indique si el campo queda activo.',
        ];
    }
}
