<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
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

    private function guard(): ContentItemSelectionService
    {
        return app(ContentItemSelectionService::class);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function assertRejected(ContentProject $project, array $selection, string $field): void
    {
        try {
            $this->guard()->assertValidSelections($project, $selection);
            $this->fail("A selection incompatible with the project/field {$field} must be rejected.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
