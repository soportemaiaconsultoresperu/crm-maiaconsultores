<?php

namespace App\Http\Requests\Content;

use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentItem;
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
        return $this->withoutHiddenFields([
            'planned_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
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
        ]);
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
                    'content_pillar_id' => $this->visibleInput('content_pillar_id', null),
                    'content_topic_id' => $this->visibleInput('content_topic_id', null),
                    'objectives' => $this->visibleInput('objectives', []),
                    'formats' => $this->visibleInput('formats', []),
                    'channels' => $this->visibleInput('channels', []),
                ],
                $this->currentContentItem(),
            );
        }];
    }

    /**
     * Drop the rules of the standard fields the project hides (S2): their
     * submitted values are neither validated nor returned by validated(), so the
     * service keeps whatever is stored.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function withoutHiddenFields(array $rules): array
    {
        $project = $this->route('contentProject');

        if ($project instanceof ContentProject) {
            foreach ($project->hiddenStandardInputs() as $input) {
                unset($rules[$input], $rules[$input.'.*']);
            }
        }

        return $rules;
    }

    /**
     * The submitted value of a standard input, or the default when the project
     * hides it, so a hidden selection is never checked.
     */
    private function visibleInput(string $input, mixed $default): mixed
    {
        $project = $this->route('contentProject');

        if ($project instanceof ContentProject && in_array($input, $project->hiddenStandardInputs(), true)) {
            return $default;
        }

        return $this->input($input, $default);
    }

    /**
     * The content being edited, resolved inside the addressed project so a
     * foreign id can never be used to smuggle a stored selection. A creation
     * request has no such row, so every selection is new and must be active.
     */
    private function currentContentItem(): ?ContentItem
    {
        $project = $this->route('contentProject');
        $id = $this->route('contentItem');

        if (! $project instanceof ContentProject || $id === null) {
            return null;
        }

        return ContentItem::query()
            ->contents()
            ->where('content_project_id', $project->getKey())
            ->find((int) $id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_date.required' => 'Indique la fecha planificada.',
            'planned_date.date_format' => 'La fecha planificada no es válida.',
            'planned_date.after_or_equal' => 'La fecha planificada debe estar entre los años 2000 y 2099.',
            'planned_date.before_or_equal' => 'La fecha planificada debe estar entre los años 2000 y 2099.',
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
