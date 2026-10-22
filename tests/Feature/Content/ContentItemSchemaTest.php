<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentItemKind;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T6a — content item schema and status vocabulary.
 *
 * Covers the additive `content_items` table, its three pivot tables, the
 * conventional columns and the four-state status vocabulary. No UI, routes or
 * custom-field values are involved in this work unit.
 */
class ContentItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_item_table_has_the_conventional_columns(): void
    {
        $this->assertTrue(Schema::hasTable('content_items'));

        foreach ([
            'id',
            'content_project_id',
            'kind',
            'planned_at',
            'content_pillar_id',
            'content_topic_id',
            'status',
            'title',
            'body',
            'hashtags',
            'notes',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('content_items', $column), "Missing content_items.{$column}");
        }
    }

    public function test_multi_select_pivot_tables_have_the_unique_pair(): void
    {
        $pivots = [
            'content_item_objective' => 'content_objective_id',
            'content_item_format' => 'content_format_id',
            'content_item_channel' => 'content_channel_id',
        ];

        foreach ($pivots as $table => $relatedKey) {
            $this->assertTrue(Schema::hasTable($table));
            foreach (['id', 'content_item_id', $relatedKey, 'created_at', 'updated_at'] as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Missing {$table}.{$column}");
            }
        }
    }

    public function test_items_are_soft_deleted_and_never_physically_removed(): void
    {
        $item = ContentItem::factory()->create();

        $item->delete();

        $this->assertSoftDeleted('content_items', ['id' => $item->id]);
        $this->assertNull(ContentItem::find($item->id));
        $this->assertNotNull(ContentItem::withTrashed()->find($item->id));
    }

    public function test_status_vocabulary_is_the_single_source_of_truth(): void
    {
        $this->assertSame(
            ['incomplete', 'in_progress', 'complete_pending_publish', 'published'],
            ContentItemStatus::values(),
        );

        $this->assertSame([
            'incomplete' => 'Incompleto',
            'in_progress' => 'En proceso',
            'complete_pending_publish' => 'Completo pero falta publicar',
            'published' => 'Publicado',
        ], ContentItemStatus::labels());

        $this->assertSame(
            ['Incompleto', 'En proceso', 'Completo pero falta publicar', 'Publicado'],
            array_values(ContentItemStatus::labels()),
        );

        $this->assertTrue(ContentItemStatus::Published->isPublished());
        foreach ([
            ContentItemStatus::Incomplete,
            ContentItemStatus::InProgress,
            ContentItemStatus::CompletePendingPublish,
        ] as $status) {
            $this->assertFalse($status->isPublished());
        }
    }

    public function test_only_the_four_vocabulary_values_are_accepted(): void
    {
        foreach (ContentItemStatus::cases() as $status) {
            $item = ContentItem::factory()->create(['status' => $status]);
            $this->assertSame($status, $item->fresh()->status);
        }

        $this->expectException(\ValueError::class);
        ContentItem::factory()->create(['status' => 'estado_inventado']);
    }

    public function test_new_rows_default_to_a_planned_content_in_the_first_status(): void
    {
        $item = new ContentItem;

        $this->assertSame(ContentItemKind::Content, $item->kind);
        $this->assertSame(ContentItemStatus::Incomplete, $item->status);

        $stored = ContentItem::factory()->create();
        $this->assertSame('content', $stored->getRawOriginal('kind'));
        $this->assertSame('incomplete', $stored->getRawOriginal('status'));
    }

    public function test_kind_vocabulary_distinguishes_content_and_idea(): void
    {
        $this->assertSame(['content', 'idea'], ContentItemKind::values());
        $this->assertSame('Contenido', ContentItemKind::Content->label());
        $this->assertSame('Idea', ContentItemKind::Idea->label());
    }

    public function test_pivot_unique_constraint_rejects_a_duplicate_pair(): void
    {
        $item = ContentItem::factory()->create();
        $objective = ContentObjective::factory()->create();
        $format = ContentFormat::factory()->create();
        $channel = ContentChannel::factory()->create();

        $item->objectives()->attach($objective->id);
        $item->formats()->attach($format->id);
        $item->channels()->attach($channel->id);

        foreach ([
            fn () => $item->objectives()->attach($objective->id),
            fn () => $item->formats()->attach($format->id),
            fn () => $item->channels()->attach($channel->id),
        ] as $attachAgain) {
            try {
                $attachAgain();
                $this->fail('A duplicate pivot pair must violate the unique constraint.');
            } catch (QueryException $exception) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, $item->objectives()->count());
        $this->assertSame(1, $item->formats()->count());
        $this->assertSame(1, $item->channels()->count());
    }

    public function test_an_item_belongs_to_a_project(): void
    {
        $project = ContentProject::factory()->create();
        $item = ContentItem::factory()->for($project, 'project')->create();

        $this->assertTrue($item->fresh()->project->is($project));
        $this->assertSame($project->id, (int) $item->fresh()->content_project_id);
    }
}
