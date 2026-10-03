<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Services\Content\ContentItemSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T6a — content item selection guard.
 *
 * Covers the rule that a pillar/topic must belong to the addressed project and
 * to each other (delegated to the existing catalog guard) and that objectives,
 * formats and channels must be active options of that same project.
 */
class ContentItemSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_active_selections_are_accepted(): void
    {
        $project = ContentProject::factory()->create();
        $pillar = ContentPillar::factory()->for($project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $objective = ContentObjective::factory()->for($project, 'project')->create();
        $format = ContentFormat::factory()->for($project, 'project')->create();
        $channel = ContentChannel::factory()->for($project, 'project')->create();

        $this->guard()->assertValidSelections($project, [
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'objectives' => [$objective->id],
            'formats' => [$format->id],
            'channels' => [$channel->id],
        ]);

        // A pillar without a topic, and an idea without either, are both valid.
        $this->guard()->assertValidSelections($project, ['content_pillar_id' => $pillar->id]);
        $this->guard()->assertValidSelections($project, []);
        $this->guard()->assertValidSelections($project, [
            'content_pillar_id' => null,
            'content_topic_id' => null,
        ]);

        $this->assertTrue($pillar->fresh()->is_active);
    }

    public function test_pillar_from_another_project_is_rejected(): void
    {
        $project = ContentProject::factory()->create();
        $foreign = ContentPillar::factory()->create();

        $this->assertRejected($project, ['content_pillar_id' => $foreign->id], 'content_pillar_id');
    }

    public function test_topic_from_another_pillar_is_rejected(): void
    {
        $pillar = ContentPillar::factory()->create();
        $sibling = ContentPillar::factory()->for($pillar->project, 'project')->create();
        $topic = ContentTopic::factory()->for($sibling, 'pillar')->create();

        $this->assertRejected($pillar->project, [
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
        ], 'content_topic_id');
    }

    public function test_topic_without_a_pillar_is_rejected(): void
    {
        $project = ContentProject::factory()->create();
        $topic = ContentTopic::factory()->create();

        $this->assertRejected($project, ['content_topic_id' => $topic->id], 'content_topic_id');
    }

    public function test_inactive_pillar_or_topic_is_rejected(): void
    {
        $topic = ContentTopic::factory()->create();
        $pillar = $topic->pillar;

        $topic->deactivate();
        $this->assertRejected($pillar->project, [
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
        ], 'content_topic_id');

        $topic->reactivate();
        $pillar->deactivate();
        $this->assertRejected($pillar->project, [
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
        ], 'content_pillar_id');
    }

    public function test_objective_format_and_channel_from_another_project_are_rejected(): void
    {
        $project = ContentProject::factory()->create();

        $this->assertRejected($project, [
            'objectives' => [ContentObjective::factory()->create()->id],
        ], 'objectives');

        $this->assertRejected($project, [
            'formats' => [ContentFormat::factory()->create()->id],
        ], 'formats');

        $this->assertRejected($project, [
            'channels' => [ContentChannel::factory()->create()->id],
        ], 'channels');
    }

    public function test_inactive_objective_format_and_channel_are_rejected(): void
    {
        $project = ContentProject::factory()->create();

        $objective = ContentObjective::factory()->for($project, 'project')->create();
        $format = ContentFormat::factory()->for($project, 'project')->create();
        $channel = ContentChannel::factory()->for($project, 'project')->create();

        $objective->deactivate();
        $format->deactivate();
        $channel->deactivate();

        $this->assertRejected($project, ['objectives' => [$objective->id]], 'objectives');
        $this->assertRejected($project, ['formats' => [$format->id]], 'formats');
        $this->assertRejected($project, ['channels' => [$channel->id]], 'channels');
    }

    public function test_missing_objective_format_or_channel_is_rejected(): void
    {
        $project = ContentProject::factory()->create();
        $own = ContentObjective::factory()->for($project, 'project')->create();

        $this->assertRejected($project, ['objectives' => [$own->id, 999999]], 'objectives');
        $this->assertRejected($project, ['formats' => [999999]], 'formats');
        $this->assertRejected($project, ['channels' => [999999]], 'channels');
    }

    public function test_retired_selections_already_stored_on_the_item_are_preserved(): void
    {
        $project = ContentProject::factory()->create();
        $pillar = ContentPillar::factory()->for($project, 'project')->create();
        $topic = ContentTopic::factory()->for($pillar, 'pillar')->create();
        $objective = ContentObjective::factory()->for($project, 'project')->create();
        $format = ContentFormat::factory()->for($project, 'project')->create();
        $channel = ContentChannel::factory()->for($project, 'project')->create();

        $item = ContentItem::factory()->for($project, 'project')->create([
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
        ]);
        $item->objectives()->attach($objective);
        $item->formats()->attach($format);
        $item->channels()->attach($channel);

        // The catalog retires every option the row already references.
        $pillar->deactivate();
        $topic->deactivate();
        $objective->deactivate();
        $format->deactivate();
        $channel->deactivate();

        $this->guard()->assertValidSelections($project, [
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
            'objectives' => [$objective->id],
            'formats' => [$format->id],
            'channels' => [$channel->id],
        ], $item);

        $this->assertFalse($pillar->fresh()->is_active);
        $this->assertFalse($objective->fresh()->is_active);
    }

    public function test_a_new_retired_selection_is_rejected_next_to_a_preserved_one(): void
    {
        $project = ContentProject::factory()->create();
        $kept = ContentObjective::factory()->for($project, 'project')->create();
        $added = ContentObjective::factory()->for($project, 'project')->create();

        $item = ContentItem::factory()->for($project, 'project')->create();
        $item->objectives()->attach($kept);

        $kept->deactivate();
        $added->deactivate();

        // The stored retired objective stays; the newly chosen one is refused.
        $this->assertRejected($project, ['objectives' => [$kept->id, $added->id]], 'objectives', $item);

        $this->guard()->assertValidSelections($project, ['objectives' => [$kept->id]], $item);
        $this->assertTrue($item->objectives()->whereKey($kept->id)->exists());
    }

    public function test_foreign_ownership_is_refused_even_when_the_value_is_already_stored(): void
    {
        $project = ContentProject::factory()->create();
        $foreignProject = ContentProject::factory()->create();
        $foreignPillar = ContentPillar::factory()->for($foreignProject, 'project')->create();
        $foreignObjective = ContentObjective::factory()->for($foreignProject, 'project')->create();

        $pillar = ContentPillar::factory()->for($project, 'project')->create();
        $sibling = ContentPillar::factory()->for($project, 'project')->create();
        $topic = ContentTopic::factory()->for($sibling, 'pillar')->create();

        $item = ContentItem::factory()->for($project, 'project')->create([
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
        ]);
        $item->objectives()->attach($foreignObjective);

        // A pillar from another project is refused even when a pillar is stored.
        $this->assertRejected($project, ['content_pillar_id' => $foreignPillar->id], 'content_pillar_id', $item);

        // A stored topic from another pillar is refused in every case.
        $this->assertRejected($project, [
            'content_pillar_id' => $pillar->id,
            'content_topic_id' => $topic->id,
        ], 'content_topic_id', $item);

        // A foreign-project objective is refused even as the stored value.
        $this->assertRejected($project, ['objectives' => [$foreignObjective->id]], 'objectives', $item);
    }

    private function guard(): ContentItemSelectionService
    {
        return app(ContentItemSelectionService::class);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function assertRejected(ContentProject $project, array $selection, string $field, ?ContentItem $current = null): void
    {
        try {
            $this->guard()->assertValidSelections($project, $selection, $current);
            $this->fail("A selection incompatible with the project/field {$field} must be rejected.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
