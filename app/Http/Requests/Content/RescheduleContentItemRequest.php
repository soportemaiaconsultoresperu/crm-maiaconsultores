<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for rescheduling a planned content (T8a).
 *
 * A reschedule only moves the planned instant, so it accepts exactly the date
 * and time the editor submits, validated with the same rules the content editor
 * uses (`date_format:Y-m-d` and `date_format:H:i`). The values are the Peru
 * wall clock; the controller converts them to a UTC instant through the
 * service, so the content lands in the correct month of the grid.
 *
 * Like every content mutation, reachability is resolved by the `contentProject`
 * route binding (404 for a foreign project) and the `update` ability is
 * required. The submitted `revision` is the optimistic-concurrency token: a
 * stale value is refused by the service, so a reschedule cannot silently undo
 * a concurrent edit.
 */
class RescheduleContentItemRequest extends FormRequest
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
            'planned_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
            'planned_time' => ['required', 'date_format:H:i'],
            'revision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_date.required' => 'Indique la nueva fecha planificada.',
            'planned_date.date_format' => 'La fecha planificada no es válida.',
            'planned_date.after_or_equal' => 'La fecha planificada debe estar entre los años 2000 y 2099.',
            'planned_date.before_or_equal' => 'La fecha planificada debe estar entre los años 2000 y 2099.',
            'planned_time.required' => 'Indique el nuevo horario planificado.',
            'planned_time.date_format' => 'El horario planificado no es válido.',
            'revision.required' => 'Faltó la versión del contenido. Vuelva a abrir la pantalla e intente de nuevo.',
            'revision.integer' => 'La versión del contenido no es válida.',
            'revision.min' => 'La versión del contenido no es válida.',
        ];
    }
}
