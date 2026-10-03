<?php

namespace App\Services\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentProject;
use Illuminate\Validation\ValidationException;

/**
 * Server-side schema guard for project-defined custom fields. Later work units
 * (T5b administration and T6 value storage) call it instead of trusting
 * caller-supplied attributes.
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
}
