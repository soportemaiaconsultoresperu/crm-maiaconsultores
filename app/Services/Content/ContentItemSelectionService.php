<?php

namespace App\Services\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
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
 * Later work units call this method before persisting an item, so a foreign or
 * retired catalog option is rejected with a validation error instead of a raw
 * foreign-key or silent inconsistency.
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
     *
     * @throws ValidationException
     */
    public function assertValidSelections(ContentProject $project, array $selection): void
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
            $this->catalogs->assertValidSelection($project, $pillarId, $topicId);
        }

        $this->assertActiveProjectOptions($project, ContentObjective::class, $selection['objectives'] ?? [], 'objectives');
        $this->assertActiveProjectOptions($project, ContentFormat::class, $selection['formats'] ?? [], 'formats');
        $this->assertActiveProjectOptions($project, ContentChannel::class, $selection['channels'] ?? [], 'channels');
    }

    /**
     * Every referenced option must belong to the addressed project and be
     * active. Ownership alone is not enough: a retired option stays readable on
     * historical rows but cannot be selected on a new one.
     *
     * @param  class-string<\App\Models\Content\ContentCatalog>  $model
     * @param  array<int, int|string>|mixed  $ids
     */
    private function assertActiveProjectOptions(ContentProject $project, string $model, mixed $ids, string $field): void
    {
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

        $valid = $model::query()
            ->where('content_project_id', $project->getKey())
            ->whereIn('id', $resolved->all())
            ->where('is_active', true)
            ->count();

        if ($valid !== $resolved->count()) {
            throw ValidationException::withMessages([
                $field => 'La opción no pertenece al proyecto o no está disponible.',
            ]);
        }
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
