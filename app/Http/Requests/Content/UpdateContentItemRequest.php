<?php

namespace App\Http\Requests\Content;

/**
 * Input contract for editing a planned content (T6c).
 *
 * The editable fields and their rules are exactly the creation contract, so
 * this request reuses it instead of drifting. Reachability and the `update`
 * ability are inherited with it.
 *
 * On top of the creation rules, an edit must carry the `revision` the form
 * loaded (T8a): the service refuses a stale revision instead of silently
 * overwriting a concurrent edit. Requiring the token here means a save without
 * it is rejected rather than treated as an implicit last-write-wins.
 */
class UpdateContentItemRequest extends StoreContentItemRequest
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
            'revision.required' => 'Faltó la versión del contenido. Vuelva a abrir la pantalla de edición e intente de nuevo.',
            'revision.integer' => 'La versión del contenido no es válida.',
            'revision.min' => 'La versión del contenido no es válida.',
        ];
    }
}
