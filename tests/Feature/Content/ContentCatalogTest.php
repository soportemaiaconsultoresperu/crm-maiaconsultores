<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use App\Services\Content\ContentCatalogSelectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContentCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_CATALOGS = [
        ContentPillar::class,
        ContentObjective::class,
        ContentFormat::class,
        ContentChannel::class,
    ];

    public function test_catalogs_have_audit_columns_and_use_deactivation_not_soft_deletes(): void
    {
        foreach ([...self::PROJECT_CATALOGS, ContentTopic::class] as $model) {
            $table = (new $model)->getTable();
            foreach (['id', 'name', 'slug', 'sort', 'is_active', 'created_by', 'updated_by', 'created_at', 'updated_at'] as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Missing {$table}.{$column}");
            }
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'));
        }
        $this->assertFalse(Schema::hasColumn('content_topics', 'content_project_id'));
    }

    public function test_all_catalogs_are_created_per_project(): void
    {
        $first = ContentProject::factory()->create();
        $second = ContentProject::factory()->create();

        foreach (self::PROJECT_CATALOGS as $model) {
            $one = $model::factory()->for($first, 'project')->create(['slug' => 'shared-slug']);
            $two = $model::factory()->for($second, 'project')->create(['slug' => 'shared-slug']);
            $this->assertTrue($one->project->is($first));
            $this->assertTrue($two->project->is($second));
            $this->assertNotSame($one->id, $two->id);
            $this->assertSame(1, $model::where('content_project_id', $first->id)->count());
        }

        $pillar = ContentPillar::factory()->for($first, 'project')->withTopics(2)->create();
        $other = ContentPillar::factory()->for($second, 'project')->withTopics(2)->create();
        foreach ([$pillar, $other] as $owner) {
            $this->assertCount(2, $owner->topics);
            foreach ($owner->topics as $topic) {
                $this->assertTrue($topic->pillar->is($owner));
                $this->assertTrue($topic->pillar->project->is($owner->project));
            }
        }
    }

    public function test_composed_factory_creates_one_project_with_its_pillar_and_topics(): void
    {
        $pillar = ContentPillar::factory()->withTopics(3)->create();

        $this->assertSame(1, ContentProject::count());
        $this->assertSame(1, ContentPillar::count());
        $this->assertSame(3, $pillar->topics()->count());
        $this->assertSame(3, ContentTopic::count());
    }

    public function test_project_slug_constraints_reject_duplicates_including_inactive_rows(): void
    {
        $project = ContentProject::factory()->create();
        foreach (self::PROJECT_CATALOGS as $model) {
            $model::factory()->for($project, 'project')->create(['slug' => 'reserved'])->deactivate();
            try {
                $model::factory()->for($project, 'project')->create(['slug' => 'reserved']);
                $this->fail("{$model} must reject a duplicate slug in the same project.");
            } catch (QueryException $exception) {
                $this->assertSame(1, $model::where('content_project_id', $project->id)->count());
            }
        }
    }

    public function test_topic_slugs_are_unique_per_pillar_not_per_project_or_globally(): void
    {
        $pillar = ContentPillar::factory()->create();
        $sibling = ContentPillar::factory()->for($pillar->project, 'project')->create();
        $foreign = ContentPillar::factory()->create();
        foreach ([$pillar, $sibling, $foreign] as $owner) {
            ContentTopic::factory()->for($owner, 'pillar')->create(['slug' => 'shared-topic']);
        }
        $this->assertSame(3, ContentTopic::count());

        $this->expectException(QueryException::class);
        ContentTopic::factory()->for($pillar, 'pillar')->create(['slug' => 'shared-topic']);
    }

    public function test_deactivation_preserves_rows_relations_and_ordered_active_selection(): void
    {
        foreach ([...self::PROJECT_CATALOGS, ContentTopic::class] as $model) {
            $retired = $model::factory()->create(['sort' => 0]);
            $later = $model::factory()->create(['sort' => 20]);
            $earlier = $model::factory()->create(['sort' => 10]);
            $tie = $model::factory()->create(['sort' => 10]);
            $retired->deactivate();

            $this->assertFalse($retired->fresh()->is_active);
            $this->assertNotNull($model::find($retired->id));
            $this->assertDatabaseHas($retired->getTable(), ['id' => $retired->id, 'is_active' => false]);
            $this->assertSame([$earlier->id, $tie->id, $later->id], $model::active()->pluck('id')->all());
            $this->assertSame(4, $model::count());
            $this->assertIsInt($earlier->fresh()->sort);
            $retired->reactivate();
            $this->assertTrue($retired->fresh()->is_active);
            $this->assertSame(4, $model::active()->count());
        }

        $topic = ContentTopic::factory()->create();
        $topic->pillar->deactivate();
        $topic->deactivate();
        $this->assertNotNull($topic->fresh()->pillar);
        $this->assertNotNull($topic->fresh()->pillar->project);
    }

    public function test_catalog_row_audit_records_authenticated_actor(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        foreach ([...self::PROJECT_CATALOGS, ContentTopic::class] as $model) {
            $catalog = $model::factory()->create();
            $this->assertSame($actor->id, $catalog->created_by);
            $catalog->deactivate();
            $this->assertSame($actor->id, $catalog->fresh()->updated_by);
        }
    }

    public function test_dependency_guard_accepts_matching_selection_and_optional_topic(): void
    {
        $topic = ContentTopic::factory()->create();
        $guard = new ContentCatalogSelectionService;
        $guard->assertValidSelection($topic->pillar->project, $topic->content_pillar_id, $topic->id);
        $guard->assertValidSelection($topic->pillar->project, $topic->content_pillar_id);
        $this->assertTrue($topic->fresh()->is_active);
    }

    public function test_dependency_guard_rejects_topic_from_another_pillar_in_same_project(): void
    {
        $pillar = ContentPillar::factory()->create();
        $sibling = ContentPillar::factory()->for($pillar->project, 'project')->create();
        $topic = ContentTopic::factory()->for($sibling, 'pillar')->create();

        $this->assertRejected($pillar->project, $pillar->id, $topic->id, 'content_topic_id');
    }

    public function test_dependency_guard_rejects_pillar_from_another_project(): void
    {
        $project = ContentProject::factory()->create();
        $foreign = ContentPillar::factory()->create();

        $this->assertRejected($project, $foreign->id, null, 'content_pillar_id');
    }

    public function test_topic_cannot_be_selected_for_a_foreign_project_with_either_pillar(): void
    {
        $local = ContentPillar::factory()->create();
        $topic = ContentTopic::factory()->create();

        $this->assertRejected($local->project, $topic->content_pillar_id, $topic->id, 'content_pillar_id');
        $this->assertRejected($local->project, $local->id, $topic->id, 'content_topic_id');
        $this->assertRejected($local->project, $topic->content_pillar_id, $topic->id, 'content_pillar_id', false);
    }

    public function test_inactive_options_are_rejected_for_new_selections_but_history_is_readable(): void
    {
        $topic = ContentTopic::factory()->create();
        $pillar = $topic->pillar;
        $topic->deactivate();
        $this->assertRejected($pillar->project, $pillar->id, $topic->id, 'content_topic_id');
        $topic->reactivate();
        $pillar->deactivate();
        $this->assertRejected($pillar->project, $pillar->id, $topic->id, 'content_pillar_id');
        $topic->deactivate();
        (new ContentCatalogSelectionService)->assertValidSelection($pillar->project, $pillar->id, $topic->id, false);
        $this->assertNotNull($topic->fresh()->pillar);
    }

    public function test_missing_catalog_ids_are_rejected(): void
    {
        $pillar = ContentPillar::factory()->create();
        $this->assertRejected($pillar->project, $pillar->id + 1000, null, 'content_pillar_id');
        $this->assertRejected($pillar->project, $pillar->id, 1000, 'content_topic_id');
    }

    private function assertRejected(ContentProject $project, int $pillarId, ?int $topicId, string $field, bool $requireActive = true): void
    {
        try {
            (new ContentCatalogSelectionService)->assertValidSelection($project, $pillarId, $topicId, $requireActive);
            $this->fail('Invalid catalog selection must raise a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
