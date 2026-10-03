<?php

namespace App\Services\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentProject;
use Illuminate\Validation\ValidationException;

/**
 * Server-side dependency guard for content items and ideas.
 *
 * It reuses ContentCatalogSelectionService for the pillar/topic rule (project
 * ownership plus pillar/topic consistency, and both must be active) instead of
 * re-implementing it, and adds the active project-option check that the catalog
 * guard does not perform for objectives, formats and channels.
 *
 * An edit re-sends the selections the row already holds. Those unchanged
 * selections are tolerated even when the catalog has since retired the option,
 * because a retired option stays on a historical reference while it cannot be
 * chosen for a new one. A selection the row did not already hold is new and
 * must be an active option of the project. Ownership is enforced either way,
 * so a foreign option is refused even when it is the previously stored value.
 *
 * Later work units call this method before persisting an item, so a foreign or
 * newly chosen retired catalog option is rejected with a validation error
 * instead of a raw foreign-key or silent inconsistency.
 */
class ContentItemSelectionService
{
    public function __construct(
        private readonly ContentCatalogSelectionService $catalogs = new ContentCatalogSelectionService,
    ) {}

    /**
     * @param  array{
     *     content_pillar_id?: int|string|null,
     *     content_topic_id?: int|string|null,
     *     objectives?: array<int, int|string>,
     *     formats?: array<int, int|string>,
     *     channels?: array<int, int|string>,
     * }  $selection
     * @param  ContentItem|null  $current  The row being edited, so its already
     *                                     stored selections can be preserved; null on create.
     *
     * @throws ValidationException
     */
    public function assertValidSelections(ContentProject $project, array $selection, ?ContentItem $current = null): void
    {
        $pillarId = $this->nullableId($selection['content_pillar_id'] ?? null);
        $topicId = $this->nullableId($selection['content_topic_id'] ?? null);

        // A topic can never exist without its pillar.
        if ($pillarId === null && $topicId !== null) {
            throw ValidationException::withMessages([
                'content_topic_id' => 'El tema requiere un pilar.',
            ]);
        }

        if ($pillarId !== null) {
            // A pillar or topic that is already stored on the edited row is
            // preserved when retired; a different one is new and must be active.
            $this->catalogs->assertValidSelection(
                $project,
                $pillarId,
                $topicId,
                pillarMustBeActive: $current === null || (int) $current->content_pillar_id !== $pillarId,
                topicMustBeActive: $current === null || (int) $current->content_topic_id !== $topicId,
            );
        }

        $this->assertActiveProjectOptions($project, ContentObjective::class, 'objectives', $selection['objectives'] ?? [], $current);
        $this->assertActiveProjectOptions($project, ContentFormat::class, 'formats', $selection['formats'] ?? [], $current);
        $this->assertActiveProjectOptions($project, ContentChannel::class, 'channels', $selection['channels'] ?? [], $current);
    }

    /**
     * Every referenced option must belong to the addressed project. A selection
     * the row did not already hold must also be active; an option that is
     * already stored is preserved when retired, because the historical
     * reference keeps its option.
     *
     * @param  class-string<\App\Models\Content\ContentCatalog>  $model
     * @param  array<int, int|string>|mixed  $ids
     */
    private function assertActiveProjectOptions(
        ContentProject $project,
        string $model,
        string $field,
        mixed $ids,
        ?ContentItem $current,
    ): void {
        $resolved = collect(is_array($ids) ? $ids : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($resolved->isEmpty()) {
            return;
        }

        if (! $project->exists) {
            throw ValidationException::withMessages([
                $field => 'El proyecto no es válido.',
            ]);
        }

        // The values already stored on the edited row: retired but tolerated.
        $preserved = $current === null
            ? []
            : $current->{$field}()->pluck((new $model)->getQualifiedKeyName())
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

        $owned = $model::query()
            ->where('content_project_id', $project->getKey())
            ->whereIn('id', $resolved->all())
            ->get(['id', 'is_active'])
            ->keyBy('id');

        foreach ($resolved as $id) {
            $option = $owned->get($id);

            // Ownership holds in every case, so an option from another project
            // is refused even when it is the previously stored value.
            if ($option === null) {
                throw ValidationException::withMessages([
                    $field => 'La opción no pertenece al proyecto o no está disponible.',
                ]);
            }

            // A retired option is preserved only while it stays on the row that
            // already referenced it; choosing it as a new value is refused.
            if (! $option->is_active && ! in_array($id, $preserved, true)) {
                throw ValidationException::withMessages([
                    $field => 'La opción no pertenece al proyecto o no está disponible.',
                ]);
            }
        }
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
