<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for writing an important-date annotation (T9).
 *
 * The date is the Peru wall clock day the editor clicked; the controller
 * converts it to a UTC instant through ContentCalendarService, so nothing here
 * assumes an offset. Reachability is resolved by the `contentProject` route
 * binding (404 for a foreign project) before this request runs, and the
 * `update` ability on the addressed project is required because this is a
 * mutation.
 *
 * Several annotations may share one day, so no rule rejects a day already used.
 */
class StoreContentCalendarAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('contentProject');

        return $project instanceof ContentProject
            && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Indique la fecha de la anotación.',
            'date.date_format' => 'La fecha de la anotación no es válida.',
            'name.required' => 'Indique el nombre de la fecha importante.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
        ];
    }
}
