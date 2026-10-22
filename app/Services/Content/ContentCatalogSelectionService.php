<?php

namespace App\Services\Content;

use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use Illuminate\Validation\ValidationException;

/** Server-side dependency guard for future content and custom-field writers. */
class ContentCatalogSelectionService
{
    /** Validate a project-owned option, including retired historical options. */
    public function assertProjectOption(ContentProject $project, string $group, int $optionId): void
    {
        $model = app(ContentCatalogAdministrationService::class)->model($group);

        if ($group === 'pillars') {
            $this->assertValidSelection($project, $optionId, requireActive: false);

            return;
        }

        if ($group === 'topics') {
            throw new \InvalidArgumentException('Topics require a pillar selection.');
        }

        if (! $project->exists || ! $model::query()
            ->where('content_project_id', $project->getKey())->whereKey($optionId)->exists()) {
            throw ValidationException::withMessages([
                'option_id' => 'La opción no pertenece al proyecto.',
            ]);
        }
    }

    /**
     * Validate persisted ownership, never caller-supplied model attributes.
     * New selections require active options. Historical validation may opt out
     * of activity checks, but never of project/pillar ownership checks.
     * This guard does not replace project authorization.
     *
     * The per-part activity flags let a caller preserve a pillar or topic that
     * is already stored on a row even when the catalog has retired it, without
     * granting that tolerance to the other part of the same selection.
     *
     * @throws ValidationException
     */
    public function assertValidSelection(
        ContentProject $project,
        int $pillarId,
        ?int $topicId = null,
        bool $requireActive = true,
        ?bool $pillarMustBeActive = null,
        ?bool $topicMustBeActive = null,
    ): void {
        $pillarActive = $pillarMustBeActive ?? $requireActive;
        $topicActive = $topicMustBeActive ?? $requireActive;

        $pillar = ContentPillar::query()
            ->where('content_project_id', $project->getKey())
            ->whereKey($pillarId);

        if ($pillarActive) {
            $pillar->where('is_active', true);
        }

        if (! $project->exists || ! $pillar->exists()) {
            throw ValidationException::withMessages([
                'content_pillar_id' => 'El pilar no pertenece al proyecto o no está disponible.',
            ]);
        }

        if ($topicId === null) {
            return;
        }

        $topic = ContentTopic::query()
            ->where('content_pillar_id', $pillarId)
            ->whereKey($topicId);

        if ($topicActive) {
            $topic->where('is_active', true);
        }

        if (! $topic->exists()) {
            throw ValidationException::withMessages([
                'content_topic_id' => 'El tema no pertenece al pilar o no está disponible.',
            ]);
        }
    }
}
