<?php

namespace App\Http\Requests\Content;

use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentItemSelectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Input contract for creating a planned content (T6c).
 *
 * The date and time are the Peru wall clock the editor typed; the controller
 * converts them to a UTC instant through the service, so nothing here assumes
 * an offset. Reachability is resolved by the `contentProject` route binding
 * (404 for a foreign project), and the `update` ability on the addressed
 * project is required because this is a mutation.
 *
 * Catalog ownership is not re-derived here: the topic/pillar and the
 * objectives/formats/channels are handed to ContentItemSelectionService, the
 * same guard the rest of the module uses. Custom values are validated by
 * ContentFieldValueService when they are stored, so their type rules live in
 * one place.
 */
class StoreContentItemRequest extends FormRequest
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
            'content_pillar_id' => ['nullable', 'integer'],
            'content_topic_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(ContentItemStatus::values())],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'hashtags' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'objectives' => ['sometimes', 'array'],
            'objectives.*' => ['integer'],
            'formats' => ['sometimes', 'array'],
            'formats.*' => ['integer'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['integer'],
            'fields' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            app(ContentItemSelectionService::class)->assertValidSelections(
                $this->route('contentProject'),
                [
                    'content_pillar_id' => $this->input('content_pillar_id'),
                    'content_topic_id' => $this->input('content_topic_id'),
                    'objectives' => $this->input('objectives', []),
                    'formats' => $this->input('formats', []),
                    'channels' => $this->input('channels', []),
                ],
            );
        }];
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
            'status.required' => 'Seleccione el estado del contenido.',
            'status.in' => 'El estado seleccionado no es válido.',
            'title.required' => 'Indique el gancho o título del contenido.',
            'title.max' => 'El título no puede superar los 200 caracteres.',
            'body.required' => 'Indique la descripción o copy del contenido.',
        ];
    }
}
