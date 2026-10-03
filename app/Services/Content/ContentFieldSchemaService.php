<?php

namespace App\Services\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Server-side schema guard for project-defined custom fields. Later work units
 * (T5b administration and T6 value storage) call it instead of trusting
 * caller-supplied attributes.
 *
 * T5b routes every administrative write through the create/update/state
 * methods below, so the invariants are enforced once, here, instead of being
 * re-derived in the controller or the FormRequests. The scoped `*Query()` and
 * `resolve*()` helpers own project/section ownership for the same reason.
 *
 * Invariants enforced here:
 * - a field `key` is immutable once the row exists;
 * - a section must belong to the addressed project;
 * - a field may only reference a section of its own project;
 * - list options may only be attached to a selection-type field;
 * - a field `type` cannot change while stored values depend on it.
 *
 * The type-change check is delegated to a single overridable dependency,
 * ContentFieldValueUsage. T5a resolves the NullContentFieldValueUsage default;
 * T6 binds the real value-usage implementation to that interface.
 */
class ContentFieldSchemaService
{
    public function __construct(
        private readonly ContentFieldValueUsage $valueUsage = new NullContentFieldValueUsage,
    ) {}

    /**
     * A section reaches a project only when it is stored with that project id.
     * An unsaved project cannot own anything.
     */
    public function assertSectionBelongsToProject(ContentProject $project, ?ContentFieldSection $section): void
    {
        if ($section === null) {
            return;
        }

        if (! $project->exists || (int) $section->content_project_id !== (int) $project->getKey()) {
            throw ValidationException::withMessages([
                'content_field_section_id' => 'La sección no pertenece al proyecto.',
            ]);
        }
    }

    /**
     * The stable `key` cannot be renamed once the definition is stored. A label
     * change is a separate, allowed operation.
     */
    public function assertKeyIsImmutable(ContentFieldDefinition $definition, string $newKey): void
    {
        if ($definition->exists && $definition->key !== $newKey) {
            throw ValidationException::withMessages([
                'key' => 'La clave del campo no se puede modificar una vez creada.',
            ]);
        }
    }

    /**
     * A field may only be placed in a section of its own project. Unknown or
     * foreign sections are rejected on both persisted and pending definitions.
     */
    public function assertSectionAssignment(ContentFieldDefinition $definition, ?ContentFieldSection $section): void
    {
        if ($section === null) {
            return;
        }

        if ((int) $definition->content_project_id !== (int) $section->content_project_id) {
            throw ValidationException::withMessages([
                'content_field_section_id' => 'El campo solo puede asignarse a una sección de su propio proyecto.',
            ]);
        }
    }

    /**
     * Reject options on any type that is not a single/multiple selection.
     * An empty option list is always fine.
     */
    public function assertOptionsAllowed(ContentFieldType $type, bool $hasOptions): void
    {
        if ($hasOptions && ! $type->acceptsOptions()) {
            throw ValidationException::withMessages([
                'options' => 'Solo los campos de selección admiten opciones.',
            ]);
        }
    }

    /**
     * An option belongs to a definition only within that definition's list.
     */
    public function assertOptionBelongsToDefinition(ContentFieldDefinition $definition, ContentFieldOption $option): void
    {
        if (! $definition->exists
            || (int) $option->content_field_definition_id !== (int) $definition->getKey()) {
            throw ValidationException::withMessages([
                'content_field_option_id' => 'La opción no pertenece al campo.',
            ]);
        }
    }

    /**
     * Change a stored value's type only while no value depends on it. The
     * dependency is the ContentFieldValueUsage seam, so T6 owns value detection.
     */
    public function assertTypeIsMutable(ContentFieldDefinition $definition, ContentFieldType $newType): void
    {
        if (! $definition->exists || $definition->type === $newType) {
            return;
        }

        if ($this->valueUsage->hasStoredValues($definition)) {
            throw ValidationException::withMessages([
                'type' => 'No se puede cambiar el tipo: el campo ya tiene valores registrados.',
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // Scoped reads and resolution (ownership lives here, never in callers)
    // ---------------------------------------------------------------------

    /** @return Builder<ContentFieldSection> */
    public function sectionsQuery(ContentProject $project): Builder
    {
        return ContentFieldSection::query()->where('content_project_id', $project->getKey());
    }

    /** @return Builder<ContentFieldDefinition> */
    public function fieldsQuery(ContentProject $project): Builder
    {
        return ContentFieldDefinition::query()->where('content_project_id', $project->getKey());
    }

    /** @return Builder<ContentFieldOption> */
    public function optionsQuery(ContentFieldDefinition $definition): Builder
    {
        return ContentFieldOption::query()->where('content_field_definition_id', $definition->getKey());
    }

    /**
     * Resolve a section addressed by id and require it to belong to the
     * project. An unknown or foreign section is a validation failure, so the
     * caller never has to re-check ownership.
     */
    public function resolveSection(ContentProject $project, int $sectionId): ContentFieldSection
    {
        $section = ContentFieldSection::query()->find($sectionId);

        if ($section === null) {
            throw ValidationException::withMessages([
                'content_field_section_id' => 'La sección indicada no existe.',
            ]);
        }

        $this->assertSectionBelongsToProject($project, $section);

        return $section;
    }

    /**
     * Resolve a field addressed by id and require it to belong to the project.
     */
    public function resolveField(ContentProject $project, int $fieldId): ContentFieldDefinition
    {
        $field = ContentFieldDefinition::query()->find($fieldId);

        if ($field === null || (int) $field->content_project_id !== (int) $project->getKey()) {
            throw ValidationException::withMessages([
                'field' => 'El campo no pertenece al proyecto.',
            ]);
        }

        return $field;
    }

    /**
     * Resolve an option addressed by id and require it to belong to the field.
     */
    public function resolveOption(ContentFieldDefinition $definition, int $optionId): ContentFieldOption
    {
        $option = ContentFieldOption::query()->find($optionId);

        if ($option === null) {
            throw ValidationException::withMessages([
                'content_field_option_id' => 'La opción indicada no existe.',
            ]);
        }

        $this->assertOptionBelongsToDefinition($definition, $option);

        return $option;
    }

    // ---------------------------------------------------------------------
    // Writes. The administration UI calls these, never Model::update().
    // ---------------------------------------------------------------------

    /**
     * Create a section owned by the project. Slug uniqueness is a database
     * constraint; a retired slug stays reserved because uniqueness ignores
     * `is_active`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createSection(ContentProject $project, array $attributes): ContentFieldSection
    {
        return ContentFieldSection::query()->create([
            'content_project_id' => $project->getKey(),
            'name' => $attributes['name'],
            'slug' => $attributes['slug'],
            'sort' => (int) ($attributes['sort'] ?? 0),
            'is_active' => true,
        ]);
    }

    /**
     * Rename and/or reorder an existing section. The slug is never writable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateSection(ContentFieldSection $section, array $attributes): ContentFieldSection
    {
        $section->update(Arr::only($attributes, ['name', 'sort']));

        return $section;
    }

    public function setSectionActive(ContentFieldSection $section, bool $isActive): ContentFieldSection
    {
        $isActive ? $section->reactivate() : $section->deactivate();

        return $section;
    }

    /**
     * Create a field owned by the project, optionally inside one of its
     * sections. When no `key` is supplied the stable key is derived from the
     * label and made unique within the project; retired keys stay reserved.
     * The type is fixed at creation and options only exist afterwards.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createField(ContentProject $project, ?ContentFieldSection $section, array $attributes): ContentFieldDefinition
    {
        $this->assertSectionBelongsToProject($project, $section);
        $type = $this->fieldType($attributes['type']);

        return ContentFieldDefinition::query()->create([
            'content_project_id' => $project->getKey(),
            'content_field_section_id' => $section?->getKey(),
            'key' => $attributes['key'] ?? $this->nextKey($project, (string) $attributes['label']),
            'label' => $attributes['label'],
            'type' => $type,
            'help_text' => $attributes['help_text'] ?? null,
            'required' => (bool) ($attributes['required'] ?? false),
            'sort' => (int) ($attributes['sort'] ?? 0),
            'is_active' => true,
        ]);
    }

    /**
     * Rename a field, change its required flag, move it between sections and/or
     * reorder it. The `key` stays immutable and the `type` only changes while
     * no stored value depends on it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateField(ContentFieldDefinition $definition, ?ContentFieldSection $section, array $attributes): ContentFieldDefinition
    {
        if (array_key_exists('key', $attributes)) {
            $this->assertKeyIsImmutable($definition, (string) $attributes['key']);
        }

        if (array_key_exists('type', $attributes)) {
            $this->assertTypeIsMutable($definition, $this->fieldType($attributes['type']));
        }

        $this->assertSectionAssignment($definition, $section);

        $definition->fill(Arr::only($attributes, ['key', 'label', 'type', 'help_text', 'required', 'sort']));
        $definition->content_field_section_id = $section?->getKey();
        $definition->save();

        return $definition;
    }

    public function setFieldActive(ContentFieldDefinition $definition, bool $isActive): ContentFieldDefinition
    {
        $isActive ? $definition->reactivate() : $definition->deactivate();

        return $definition;
    }

    /**
     * Create an option for a selection-type field. Any other type is refused by
     * the shared `assertOptionsAllowed()` invariant.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOption(ContentFieldDefinition $definition, array $attributes): ContentFieldOption
    {
        $this->assertOptionsAllowed($definition->type, true);

        return ContentFieldOption::query()->create([
            'content_field_definition_id' => $definition->getKey(),
            'label' => $attributes['label'],
            'slug' => $attributes['slug'],
            'sort' => (int) ($attributes['sort'] ?? 0),
            'is_active' => true,
        ]);
    }

    /**
     * Rename and/or reorder an option. The slug is never writable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateOption(ContentFieldOption $option, array $attributes): ContentFieldOption
    {
        $option->update(Arr::only($attributes, ['label', 'sort']));

        return $option;
    }

    public function setOptionActive(ContentFieldOption $option, bool $isActive): ContentFieldOption
    {
        $isActive ? $option->reactivate() : $option->deactivate();

        return $option;
    }

    /**
     * Derive a stable, project-unique key from a visible label. Uniqueness
     * ignores `is_active` on purpose: a retired key stays reserved.
     */
    public function nextKey(ContentProject $project, string $label): string
    {
        $base = Str::slug($label, '_');
        $base = $base === '' ? 'campo' : mb_substr($base, 0, 90);
        $key = $base;
        $suffix = 1;

        while (ContentFieldDefinition::query()
            ->where('content_project_id', $project->getKey())
            ->where('key', $key)
            ->exists()) {
            $suffix++;
            $key = $base.'_'.$suffix;
        }

        return $key;
    }

    private function fieldType(mixed $type): ContentFieldType
    {
        return $type instanceof ContentFieldType ? $type : ContentFieldType::from((string) $type);
    }
}
