<?php

namespace App\Http\Requests\Content;

use App\Services\Content\ContentCatalogAdministrationService;
use App\Services\Content\ContentCatalogSelectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ManageContentCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('contentProject')) ?? false;
    }

    public function rules(): array
    {
        $group = $this->route('catalogGroup');
        $model = app(ContentCatalogAdministrationService::class)->model($group);
        $project = $this->route('contentProject');
        $scopeColumn = $group === 'topics' ? 'content_pillar_id' : 'content_project_id';
        $scopeId = $group === 'topics' ? $this->input('content_pillar_id') : $project->id;
        $action = last(explode('.', $this->route()->getName()));

        $rules = ['content_project_id' => ['sometimes', 'integer', Rule::in([$project->id])]];
        if ($group === 'topics') {
            $rules['content_pillar_id'] = ['required', 'integer'];
        }
        if (in_array($action, ['store', 'update'], true)) {
            $rules['name'] = ['required', 'string', 'max:150'];
        }
        if ($action === 'store') {
            $rules['slug'] = ['required', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique((new $model)->getTable(), 'slug')->where($scopeColumn, $scopeId)];
            $rules['sort'] = ['required', 'integer', 'min:0', 'max:2147483647'];
        } else {
            $rules['slug'] = ['prohibited'];
        }
        if ($action === 'state') {
            $rules['is_active'] = ['required', 'boolean'];
        }
        if ($action === 'reorder') {
            $rules['sort'] = ['required', 'integer', 'min:0', 'max:2147483647'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $guard = app(ContentCatalogSelectionService::class);
            $project = $this->route('contentProject');
            $group = $this->route('catalogGroup');
            $optionId = $this->route('catalogOption');
            if ($group === 'topics') {
                // Retired pillars/topics remain administrable, but never move
                // an existing topic to a different historical parent.
                $guard->assertValidSelection($project, (int) $this->input('content_pillar_id'),
                    $optionId === null ? null : (int) $optionId, requireActive: false);
            } elseif ($optionId !== null) {
                $guard->assertProjectOption($project, $group, (int) $optionId);
            }
        }];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Indique un nombre para la opción.',
            'slug.required' => 'Indique un identificador para la opción.',
            'slug.unique' => 'Ya existe una opción con ese identificador, incluso si está retirada.',
            'slug.regex' => 'El identificador sólo admite minúsculas, números y guiones.',
            'slug.prohibited' => 'El identificador de una opción existente no se puede cambiar.',
            'content_project_id.in' => 'La opción debe pertenecer al proyecto seleccionado.',
            'content_pillar_id.required' => 'Seleccione un pilar para el tema.',
        ];
    }
}
