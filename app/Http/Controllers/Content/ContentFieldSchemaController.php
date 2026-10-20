<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Enums\Content\ContentFieldType;
use App\Http\Requests\Content\ManageContentFieldDefinitionRequest;
use App\Http\Requests\Content\ManageContentFieldOptionRequest;
use App\Http\Requests\Content\ManageContentFieldSectionRequest;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentFieldSchemaService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Administration UI for a project's custom-field sections, field definitions
 * and list options (T5b).
 *
 * The controller stays thin on purpose: it authorizes the addressed project
 * with the `configure` ability (administrative, refused on an archived
 * project) and then delegates every read and every
 * write to ContentFieldSchemaService, so the T5a invariants (key/type
 * immutability, section ownership, options-for-list-types-only) hold for UI
 * traffic too. No ownership or immutability rule is re-derived here.
 */
class ContentFieldSchemaController extends Controller
{
    public function __construct(private readonly ContentFieldSchemaService $schema)
    {
    }

    public function index(ContentProject $contentProject): View
    {
        Gate::authorize('configure', $contentProject);

        $sections = $this->schema->sectionsQuery($contentProject)
            ->orderBy('sort')->orderBy('id')->get();

        $fields = $this->schema->fieldsQuery($contentProject)
            ->with(['options' => fn ($options) => $options->orderBy('sort')->orderBy('id')])
            ->orderBy('sort')->orderBy('id')->get();

        return view('content.fields.index', [
            'project' => $contentProject,
            'sections' => $sections,
            'fieldTypes' => ContentFieldType::cases(),
            'fieldsBySection' => $fields->groupBy('content_field_section_id'),
            'ungroupedFields' => $fields->filter(
                fn ($field): bool => $field->content_field_section_id === null,
            )->values(),
        ]);
    }

    public function storeSection(ManageContentFieldSectionRequest $request, ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $this->schema->createSection($contentProject, $request->safe()->only(['name', 'slug', 'sort']));

        return $this->backToSchema($contentProject, 'Sección creada correctamente.');
    }

    public function updateSection(ManageContentFieldSectionRequest $request, ContentProject $contentProject, string $fieldSection): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $section = $this->schema->resolveSection($contentProject, (int) $fieldSection);
        $this->schema->updateSection($section, $request->safe()->only(['name']));

        return $this->backToSchema($contentProject, 'Sección actualizada correctamente.');
    }

    public function stateSection(ManageContentFieldSectionRequest $request, ContentProject $contentProject, string $fieldSection): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $section = $this->schema->resolveSection($contentProject, (int) $fieldSection);
        $this->schema->setSectionActive($section, $request->boolean('is_active'));

        return $this->backToSchema($contentProject, $request->boolean('is_active')
            ? 'Sección reactivada correctamente.'
            : 'Sección desactivada. Sus campos y referencias históricas se conservan.');
    }

    public function reorderSection(ManageContentFieldSectionRequest $request, ContentProject $contentProject, string $fieldSection): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $section = $this->schema->resolveSection($contentProject, (int) $fieldSection);
        $this->schema->updateSection($section, $request->safe()->only(['sort']));

        return $this->backToSchema($contentProject, 'Orden de la sección actualizado correctamente.');
    }

    public function storeField(ManageContentFieldDefinitionRequest $request, ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $section = $this->resolveTargetSection($contentProject, $request->input('content_field_section_id'));
        $this->schema->createField($contentProject, $section, $request->safe()->except('content_field_section_id'));

        return $this->backToSchema($contentProject, 'Campo creado correctamente.');
    }

    public function updateField(ManageContentFieldDefinitionRequest $request, ContentProject $contentProject, string $fieldDefinition): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $section = $this->resolveTargetSection($contentProject, $request->input('content_field_section_id'));
        $this->schema->updateField($field, $section, $request->safe()->except('content_field_section_id'));

        return $this->backToSchema($contentProject, 'Campo actualizado correctamente.');
    }

    public function stateField(ManageContentFieldDefinitionRequest $request, ContentProject $contentProject, string $fieldDefinition): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $this->schema->setFieldActive($field, $request->boolean('is_active'));

        return $this->backToSchema($contentProject, $request->boolean('is_active')
            ? 'Campo reactivado correctamente.'
            : 'Campo desactivado. Sus valores registrados se conservan.');
    }

    public function reorderField(ManageContentFieldDefinitionRequest $request, ContentProject $contentProject, string $fieldDefinition): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $this->schema->updateField($field, $field->section, $request->safe()->only(['sort']));

        return $this->backToSchema($contentProject, 'Orden del campo actualizado correctamente.');
    }

    public function storeOption(ManageContentFieldOptionRequest $request, ContentProject $contentProject, string $fieldDefinition): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $this->schema->createOption($field, $request->safe()->only(['label', 'slug', 'sort']));

        return $this->backToSchema($contentProject, 'Opción creada correctamente.');
    }

    public function updateOption(ManageContentFieldOptionRequest $request, ContentProject $contentProject, string $fieldDefinition, string $fieldOption): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $option = $this->schema->resolveOption($field, (int) $fieldOption);
        $this->schema->updateOption($option, $request->safe()->only(['label']));

        return $this->backToSchema($contentProject, 'Opción actualizada correctamente.');
    }

    public function stateOption(ManageContentFieldOptionRequest $request, ContentProject $contentProject, string $fieldDefinition, string $fieldOption): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $option = $this->schema->resolveOption($field, (int) $fieldOption);
        $this->schema->setOptionActive($option, $request->boolean('is_active'));

        return $this->backToSchema($contentProject, $request->boolean('is_active')
            ? 'Opción reactivada correctamente.'
            : 'Opción retirada. Sus referencias históricas se conservan.');
    }

    public function reorderOption(ManageContentFieldOptionRequest $request, ContentProject $contentProject, string $fieldDefinition, string $fieldOption): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $field = $this->schema->resolveField($contentProject, (int) $fieldDefinition);
        $option = $this->schema->resolveOption($field, (int) $fieldOption);
        $this->schema->updateOption($option, $request->safe()->only(['sort']));

        return $this->backToSchema($contentProject, 'Orden de la opción actualizado correctamente.');
    }

    /**
     * Resolve the optional target section of a field. An empty value means
     * "sin sección"; anything else must belong to the addressed project. The
     * id comes from the payload, so a miss is a validation error, and a
     * foreign and an unknown section get the very same message.
     */
    private function resolveTargetSection(ContentProject $project, mixed $sectionId): ?ContentFieldSection
    {
        if ($sectionId === null || $sectionId === '') {
            return null;
        }

        try {
            return $this->schema->resolveSection($project, (int) $sectionId);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'content_field_section_id' => 'La sección indicada no existe en este proyecto.',
            ]);
        }
    }

    private function backToSchema(ContentProject $project, string $message): RedirectResponse
    {
        return redirect()->route('content.fields.index', $project)->with('status', $message);
    }
}
