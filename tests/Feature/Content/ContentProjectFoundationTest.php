<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentProject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T1 — content project foundation.
 *
 * Covers the two additive tables and their Eloquent model: slug uniqueness,
 * the archived/active lifecycle, and membership attach/detach. Projects are
 * dynamic, so arbitrary names must work exactly like the seeded Maia brands.
 */
class ContentProjectFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_project_tables_have_the_conventional_columns(): void
    {
        $this->assertTrue(Schema::hasTable('content_projects'));
        $this->assertTrue(Schema::hasTable('content_project_user'));

        foreach (['id', 'name', 'slug', 'description', 'color', 'is_active', 'archived_at', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('content_projects', $column), "Missing content_projects column {$column}");
        }

        foreach (['id', 'content_project_id', 'user_id', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('content_project_user', $column), "Missing content_project_user column {$column}");
        }
    }

    public function test_a_project_is_created_with_a_slug(): void
    {
        $project = ContentProject::factory()->create([
            'name' => 'Maia Consultores',
            'slug' => 'maia-consultores',
        ]);

        $this->assertIsInt($project->id);
        $this->assertSame('maia-consultores', $project->slug);
        $this->assertSame('Maia Consultores', $project->name);
        $this->assertTrue($project->is_active);
        $this->assertNull($project->archived_at);
        $this->assertNull($project->deleted_at);

        $this->assertDatabaseHas('content_projects', [
            'id' => $project->id,
            'slug' => 'maia-consultores',
        ]);
    }

    public function test_unique_slug_constraint_is_enforced(): void
    {
        ContentProject::factory()->create(['slug' => 'maia-plagas']);

        $this->expectException(QueryException::class);

        ContentProject::factory()->create(['slug' => 'maia-plagas']);
    }

    public function test_a_project_can_be_archived_and_reactivated(): void
    {
        $project = ContentProject::factory()->create(['is_active' => true]);

        $project->archive();

        $this->assertFalse($project->fresh()->is_active);
        $this->assertNotNull($project->fresh()->archived_at);
        // Archiving retires the project; it must NOT send it to the trash.
        $this->assertNull($project->fresh()->deleted_at);
        $this->assertTrue($project->fresh()->isArchived());
        $this->assertFalse($project->fresh()->isActive());

        $this->assertSame(1, ContentProject::query()->archived()->count());
        $this->assertSame(0, ContentProject::query()->active()->count());
        $this->assertSame(1, ContentProject::query()->count());

        $project->reactivate();

        $this->assertTrue($project->fresh()->is_active);
        $this->assertNull($project->fresh()->archived_at);
        $this->assertTrue($project->fresh()->isActive());
        $this->assertSame(1, ContentProject::query()->active()->count());
        $this->assertSame(0, ContentProject::query()->archived()->count());
    }

    public function test_members_can_be_attached_and_detached(): void
    {
        $project = ContentProject::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();

        $project->members()->attach([$member->id, $other->id]);

        $this->assertSame(2, $project->members()->count());
        $this->assertTrue($project->members()->whereKey($member->id)->exists());
        $this->assertDatabaseHas('content_project_user', [
            'content_project_id' => $project->id,
            'user_id' => $member->id,
        ]);

        $project->members()->detach($member->id);

        $this->assertSame(1, $project->members()->count());
        $this->assertFalse($project->members()->whereKey($member->id)->exists());
        $this->assertDatabaseMissing('content_project_user', [
            'content_project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_membership_rows_are_unique_per_user(): void
    {
        $project = ContentProject::factory()->create();
        $member = User::factory()->create();

        $project->members()->attach($member->id);

        try {
            $project->members()->attach($member->id);
            $this->fail('Attaching the same member twice must violate the unique constraint.');
        } catch (QueryException $exception) {
            // Expected: UNIQUE(content_project_id, user_id).
        }

        $this->assertSame(
            1,
            DB::table('content_project_user')->where('content_project_id', $project->id)->count(),
        );
    }

    public function test_an_arbitrary_project_name_is_fully_supported(): void
    {
        // Nothing in the schema or model may be restricted to the three initial
        // Maia brands: any name and slug must persist like the seeded ones.
        $project = ContentProject::factory()->create([
            'name' => 'Proyecto Interno de Contenidos Q4',
            'slug' => 'proyecto-interno-de-contenidos-q4',
        ]);

        $this->assertSame('Proyecto Interno de Contenidos Q4', $project->fresh()->name);
        $this->assertSame('proyecto-interno-de-contenidos-q4', $project->fresh()->slug);
        $this->assertTrue($project->fresh()->isActive());

        $second = ContentProject::factory()->create([
            'name' => 'Lanzamiento Línea Verde',
            'slug' => 'lanzamiento-linea-verde',
        ]);

        $this->assertSame(2, ContentProject::query()->active()->count());
        $this->assertSame('Lanzamiento Línea Verde', $second->fresh()->name);
    }
}
