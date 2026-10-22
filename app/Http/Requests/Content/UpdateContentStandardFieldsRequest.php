<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input contract for choosing which optional standard fields a project uses
 * (S2).
 *
 * The form sends `standard[<key>]` = 1 (used) or 0 (hidden) for every
 * optional field. Only the hideable keys are accepted: a mandatory field
 * (fecha, hora, estado, título, copy) or an unknown key is refused, so it can
 * never be hidden. Changing the settings is administrative, so the request
 * requires `configure`, which an archived project refuses.
 */
class UpdateContentStandardFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('contentProject');

        return $project instanceof ContentProject
            && ($this->user()?->can('configure', $project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'standard' => ['required', 'array'],
            'standard.*' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unknown = array_diff(
                array_keys((array) $this->input('standard', [])),
                array_keys(ContentProject::HIDEABLE_STANDARD_FIELDS),
            );

            if ($unknown !== []) {
                $validator->errors()->add(
                    'standard',
                    'Solo puede ocultar campos opcionales. Fecha, horario, estado, gancho o título y descripción o copy son obligatorios.',
                );
            }
        }];
    }

    /**
     * The hideable keys marked as not used, in form order.
     *
     * @return list<string>
     */
    public function hiddenFields(): array
    {
        $standard = (array) $this->validated('standard', []);

        return array_values(array_filter(
            array_keys(ContentProject::HIDEABLE_STANDARD_FIELDS),
            fn (string $key): bool => array_key_exists($key, $standard) && ! filter_var($standard[$key], FILTER_VALIDATE_BOOLEAN),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'standard.required' => 'Indique qué campos estándar usa el proyecto.',
            'standard.array' => 'La configuración de campos estándar no es válida.',
            'standard.*.boolean' => 'La configuración de campos estándar no es válida.',
        ];
    }
}
