<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for editing an important-date annotation (T9).
 *
 * Same rules as creation: the annotation is resolved inside the addressed
 * project, so a foreign id is a 404, and editing never assumes the day is free
 * because several annotations may share one day.
 */
class UpdateContentCalendarAnnotationRequest extends FormRequest
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
