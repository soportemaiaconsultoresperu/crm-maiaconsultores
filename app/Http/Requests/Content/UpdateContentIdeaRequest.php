<?php

namespace App\Http\Requests\Content;

/**
 * Input contract for editing an idea (T6d).
 *
 * The editable fields and their rules are exactly the registration contract, so
 * this request reuses it instead of drifting. Reachability and the `update`
 * ability are inherited with it.
 *
 * Like a content edit, an idea edit must carry the `revision` the form loaded:
 * the service refuses a stale revision instead of silently overwriting a
 * concurrent edit.
 */
class UpdateContentIdeaRequest extends StoreContentIdeaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'revision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'revision.required' => 'Faltó la versión de la idea. Vuelva a abrir la pantalla de edición e intente de nuevo.',
            'revision.integer' => 'La versión de la idea no es válida.',
            'revision.min' => 'La versión de la idea no es válida.',
        ];
    }
}
