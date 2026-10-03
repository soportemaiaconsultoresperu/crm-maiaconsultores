<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for converting an idea into a planned content (T6d).
 *
 * The conversion must place the future content on the grid, so a planned date
 * and time are both required; the controller turns them into a UTC instant
 * through ContentItemService, so nothing here assumes an offset. Reachability is
 * resolved by the `contentProject` route binding (404 for a foreign project) and
 * the mutation requires the `update` ability on the addressed project.
 */
class ConvertContentIdeaRequest extends FormRequest
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
            'planned_date' => ['required', 'date_format:Y-m-d'],
            'planned_time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_date.required' => 'Indique la fecha planificada.',
            'planned_date.date_format' => 'La fecha planificada no es válida.',
            'planned_time.required' => 'Indique el horario planificado.',
            'planned_time.date_format' => 'El horario planificado no es válido.',
        ];
    }
}
