<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentItemSelectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input contract for registering or editing an idea (T6d).
 *
 * An idea is a proposal without a publication and without a date, so this
 * request deliberately has no `planned_date`, `planned_time` or `status` rule:
 * the row is stored dateless and with the default editorial status, and its
 * status only ever changes through conversion. Reachability is resolved by the
 * `contentProject` route binding (404 for a foreign project) and the mutation
 * requires the `update` ability on the addressed project.
 *
 * Catalog ownership is handed to ContentItemSelectionService, the same guard
 * the rest of the module uses, and custom values are validated by
 * ContentFieldValueService when they are stored, so neither rule is duplicated.
 */
class StoreContentIdeaRequest extends FormRequest
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
            'content_pillar_id' => ['nullable', 'integer'],
            'content_topic_id' => ['nullable', 'integer'],
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
                $this->currentIdea(),
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
     * The idea being edited, resolved inside the addressed project as an idea so
     * a foreign or already-converted row cannot smuggle a stored selection. A
     * creation request has no such row, so every selection is new and must be
     * active.
     */
    private function currentIdea(): ?ContentItem
    {
        $project = $this->route('contentProject');
        $id = $this->route('contentIdea');

        if (! $project instanceof ContentProject || $id === null) {
            return null;
        }

        return ContentItem::query()
            ->where('content_project_id', $project->getKey())
            ->ideas()
            ->find((int) $id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Indique el gancho o título de la idea.',
            'title.max' => 'El título no puede superar los 200 caracteres.',
            'body.required' => 'Indique la descripción o copy de la idea.',
        ];
    }
}
