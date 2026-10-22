<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentAttachmentService;
use App\Services\Content\ContentTrashService;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Review fixes for access and the trash (content-module-review-fixes T1).
 *
 * Pins the product decisions S1 (a member works on everything inside their
 * project without any module permission, while catalogs, fields, members and
 * project settings stay administrative), S2 (an archived project is read-only
 * for everybody, the administrator included, until it is reactivated), S17
 * (bulk empty-trash and file removal only after the database commit), S18
 * (restore lands on the right detail), S22 (no existence oracle in the field
 * schema) and S25 (consolidated calendar reachable from the projects index).
 */
class ContentAccessReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        Storage::fake(ContentAttachmentService::DISK);

        $this->project = ContentProject::factory()->create(['name' => 'Proyecto Miembro']);
        $this->member = $this->vendedor();
        $this->project->members()->attach($this->member);
    }

    public function test_a_member_without_module_permissions_works_inside_their_project(): void
    {
        $this->assertFalse($this->member->can('content_projects.view'));
        $this->actingAs($this->member);

        $this->get(route('content.projects.index'))->assertOk()
            ->assertSee('data-testid="sidebar-content-projects"', false)
            ->assertSee('Proyecto Miembro');
        $this->get(route('content.projects.show', $this->project))->assertOk();

        $this->post(route('content.projects.contents.store', $this->project), [
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Contenido del miembro',
            'body' => 'Texto.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $content = ContentItem::query()->where('title', 'Contenido del miembro')->sole();

        $this->post(route('content.projects.ideas.store', $this->project), [
            'title' => 'Idea del miembro',
            'body' => 'Texto.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $idea = ContentItem::query()->where('title', 'Idea del miembro')->sole();

        $this->post(route('content.projects.calendar.annotations.store', $this->project), [
            'date' => '2026-07-28',
            'name' => 'Fecha del miembro',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, ContentCalendarAnnotation::query()->where('name', 'Fecha del miembro')->count());

        // Trash and restore a content: restore lands on the content detail.
        $this->delete(route('content.projects.contents.destroy', [$this->project, $content]))
            ->assertRedirect(route('content.projects.contents.index', $this->project));
        $this->assertSoftDeleted('content_items', ['id' => $content->id]);
        $this->post(route('content.projects.contents.restore', [$this->project, $content]))
            ->assertRedirect(route('content.projects.contents.show', [$this->project, $content]));
        $this->assertNotSoftDeleted('content_items', ['id' => $content->id]);

        // An idea goes back to the ideas list, and its restore to the idea detail.
        $this->delete(route('content.projects.contents.destroy', [$this->project, $idea]))
            ->assertRedirect(route('content.projects.ideas.index', $this->project));
        $this->post(route('content.projects.contents.restore', [$this->project, $idea]))
            ->assertRedirect(route('content.projects.ideas.show', [$this->project, $idea]));
        $this->assertNotSoftDeleted('content_items', ['id' => $idea->id]);

        // The member may purge from the trash too.
        $content->delete();
        $this->get(route('content.projects.contents.trash', $this->project))->assertOk()
            ->assertSee('data-testid="content-trash-restore-'.$content->id.'"', false)
            ->assertSee('data-testid="content-trash-empty"', false);
        $this->delete(route('content.projects.contents.purge', [$this->project, $content]))->assertRedirect();
        $this->assertDatabaseMissing('content_items', ['id' => $content->id]);
    }

    public function test_a_member_cannot_configure_the_project_or_manage_its_members(): void
    {
        $pillar = ContentPillar::factory()->create(['content_project_id' => $this->project->id]);
        $this->actingAs($this->member);

        $this->get(route('content.projects.show', $this->project))->assertOk()
            ->assertDontSee('Administrar catálogos')
            ->assertDontSee('Campos personalizados')
            ->assertDontSee('data-testid="btn-edit-content-project"', false);

        $this->get(route('content.catalogs.index', $this->project))->assertForbidden();
        $this->postJson(route('content.catalogs.store', [$this->project, 'pillars']), ['name' => 'X'])->assertForbidden();
        $this->postJson(route('content.catalogs.state', [$this->project, 'pillars', $pillar->id]), ['is_active' => 0])->assertForbidden();
        $this->get(route('content.fields.index', $this->project))->assertForbidden();
        $this->postJson(route('content.fields.sections.store', $this->project), ['name' => 'S', 'slug' => 's', 'sort' => 0])->assertForbidden();
        $this->postJson(route('content.fields.store', $this->project), ['label' => 'F', 'type' => ContentFieldType::Text->value, 'sort' => 0])->assertForbidden();
        $this->get(route('content.projects.edit', $this->project))->assertForbidden();
        $this->putJson(route('content.projects.update', $this->project), ['name' => 'Nuevo', 'slug' => 'nuevo'])->assertForbidden();
        $this->postJson(route('content.projects.members.sync', $this->project), ['members' => []])->assertForbidden();
        $this->postJson(route('content.projects.archive', $this->project))->assertForbidden();

        $this->assertTrue($pillar->fresh()->is_active);
        $this->assertSame(0, ContentFieldSection::query()->count());
        $this->assertSame(0, ContentFieldDefinition::query()->count());
        $this->assertSame([$this->member->id], $this->project->members()->pluck('users.id')->all());
        $this->assertNull($this->project->fresh()->archived_at);
    }

    public function test_membership_grants_nothing_on_other_projects(): void
    {
        $foreign = ContentProject::factory()->create(['name' => 'Proyecto Ajeno']);
        $foreignItem = ContentItem::factory()->for($foreign, 'project')->create();
        $this->actingAs($this->member);

        $this->get(route('content.projects.index'))->assertOk()->assertDontSee('Proyecto Ajeno');
        $this->get(route('content.projects.show', $foreign))->assertNotFound();
        $this->postJson(route('content.projects.contents.store', $foreign), [])->assertNotFound();
        $this->postJson(route('content.projects.ideas.store', $foreign), [])->assertNotFound();
        $this->deleteJson(route('content.projects.contents.destroy', [$foreign, $foreignItem]))->assertNotFound();
        $this->deleteJson(route('content.projects.contents.trash.empty', $foreign))->assertNotFound();
        $this->assertNotSoftDeleted('content_items', ['id' => $foreignItem->id]);

        // A user with no membership at all and no permission sees no module.
        $stranger = $this->vendedor();
        $this->actingAs($stranger)->get(route('content.projects.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('dashboard'))->assertOk()
            ->assertDontSee('data-testid="sidebar-content-projects"', false);
        $this->actingAs($stranger)->get(route('content.projects.show', $this->project))->assertNotFound();
    }

    public function test_an_archived_project_is_read_only_for_members_and_admins_until_reactivated(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Histórico']);
        $trashed = ContentItem::factory()->for($this->project, 'project')->create();
        $trashed->delete();
        $annotation = ContentCalendarAnnotation::factory()->for($this->project, 'project')->onDate('2026-07-28')->create();
        $pillar = ContentPillar::factory()->create(['content_project_id' => $this->project->id]);
        $this->project->archive();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        foreach ([$this->member, $admin] as $actor) {
            $this->actingAs($actor);

            // Reads and exports stay open.
            $this->get(route('content.projects.show', $this->project))->assertOk()
                ->assertSee('data-testid="content-project-read-only-notice"', false)
                ->assertDontSee('Administrar catálogos')
                ->assertDontSee('data-testid="btn-edit-content-project"', false);
            $this->get(route('content.projects.contents.index', $this->project))->assertOk()->assertSee('Histórico');
            $this->get(route('content.projects.contents.show', [$this->project, $item]))->assertOk();
            $this->get(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-07']))->assertOk();
            $this->get(route('content.projects.contents.trash', $this->project))->assertOk()
                ->assertDontSee('data-testid="content-trash-restore-'.$trashed->id.'"', false)
                ->assertDontSee('data-testid="content-trash-empty"', false);
            $this->get(route('content.projects.contents.export.excel', $this->project))->assertOk();
            $this->get(route('content.projects.contents.export.pdf', $this->project))->assertOk();

            // Every write is refused.
            $this->postJson(route('content.projects.contents.store', $this->project), [
                'planned_date' => '2026-07-15', 'planned_time' => '09:30',
                'status' => ContentItemStatus::Incomplete->value, 'title' => 'Nuevo', 'body' => 'Texto.',
            ])->assertForbidden();
            $this->putJson(route('content.projects.contents.update', [$this->project, $item]), ['title' => 'Cambio'])->assertForbidden();
            $this->postJson(route('content.projects.ideas.store', $this->project), ['title' => 'Idea', 'body' => 'Texto.'])->assertForbidden();
            $this->postJson(route('content.projects.contents.attachments.link', [$this->project, $item]), [
                'url' => 'https://example.com/v.mp4', 'kind' => 'video',
            ])->assertForbidden();
            $this->postJson(route('content.projects.calendar.annotations.store', $this->project), ['date' => '2026-07-28', 'name' => 'X'])->assertForbidden();
            $this->deleteJson(route('content.projects.calendar.annotations.destroy', [$this->project, $annotation]))->assertForbidden();
            $this->deleteJson(route('content.projects.contents.destroy', [$this->project, $item]))->assertForbidden();
            $this->postJson(route('content.projects.contents.restore', [$this->project, $trashed]))->assertForbidden();
            $this->deleteJson(route('content.projects.contents.purge', [$this->project, $trashed]))->assertForbidden();
            $this->deleteJson(route('content.projects.contents.trash.empty', $this->project))->assertForbidden();
            $this->postJson(route('content.catalogs.state', [$this->project, 'pillars', $pillar->id]), ['is_active' => 0])->assertForbidden();
            $this->postJson(route('content.fields.sections.store', $this->project), ['name' => 'S', 'slug' => 's', 'sort' => 0])->assertForbidden();
            $this->putJson(route('content.projects.update', $this->project), ['name' => 'Nuevo', 'slug' => 'nuevo'])->assertForbidden();
            $this->postJson(route('content.projects.members.sync', $this->project), ['members' => []])->assertForbidden();
        }

        $this->assertNotSoftDeleted('content_items', ['id' => $item->id]);
        $this->assertSoftDeleted('content_items', ['id' => $trashed->id]);
        $this->assertSame(1, ContentItem::query()->withTrashed()->where('content_project_id', $this->project->id)->where('id', '!=', $trashed->id)->count());
        $this->assertNotSoftDeleted('content_calendar_annotations', ['id' => $annotation->id]);
        $this->assertTrue($pillar->fresh()->is_active);
        $this->assertSame(0, ContentFieldSection::query()->count());

        $gate = Gate::forUser($admin);
        $project = $this->project->fresh();
        $this->assertTrue($gate->allows('view', $project));
        $this->assertTrue($gate->allows('archive', $project));
        $this->assertFalse($gate->allows('update', $project));
        $this->assertFalse($gate->allows('trash', $project));
        $this->assertFalse($gate->allows('configure', $project));
        $this->assertFalse($gate->allows('assignMembers', $project));

        // The member cannot reactivate; the administrator can, and writing resumes.
        $this->actingAs($this->member)->postJson(route('content.projects.reactivate', $this->project))->assertForbidden();
        $this->actingAs($admin)->get(route('content.projects.show', $this->project))
            ->assertSee('data-testid="content-project-reactivate-form"', false);
        $this->actingAs($admin)->post(route('content.projects.reactivate', $this->project))->assertRedirect();
        $this->assertNull($this->project->fresh()->archived_at);

        $this->actingAs($this->member)
            ->post(route('content.projects.ideas.store', $this->project), ['title' => 'Idea tras reactivar', 'body' => 'Texto.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->get(route('content.projects.show', $this->project))->assertOk()
            ->assertDontSee('data-testid="content-project-read-only-notice"', false)
            ->assertSee('Administrar catálogos');
    }

    public function test_empty_trash_purges_only_this_projects_trashed_rows_and_their_files(): void
    {
        $disk = Storage::disk(ContentAttachmentService::DISK);
        $other = ContentProject::factory()->create();

        [$trashedA, $trashedPathA] = $this->itemWithFile($this->project);
        [$trashedA2, $trashedPathA2] = $this->itemWithFile($this->project, idea: true);
        [$liveA, $livePathA] = $this->itemWithFile($this->project);
        [$trashedB, $trashedPathB] = $this->itemWithFile($other);
        $trashedA->delete();
        $trashedA2->delete();
        $trashedB->delete();

        $this->actingAs($this->member)
            ->delete(route('content.projects.contents.trash.empty', $this->project))
            ->assertRedirect(route('content.projects.contents.trash', $this->project))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('content_items', ['id' => $trashedA->id]);
        $this->assertDatabaseMissing('content_items', ['id' => $trashedA2->id]);
        $this->assertDatabaseMissing('content_attachments', ['content_item_id' => $trashedA->id]);
        $this->assertFalse($disk->exists($trashedPathA));
        $this->assertFalse($disk->exists($trashedPathA2));

        $this->assertNotSoftDeleted('content_items', ['id' => $liveA->id]);
        $this->assertTrue($disk->exists($livePathA));
        $this->assertSoftDeleted('content_items', ['id' => $trashedB->id]);
        $this->assertTrue($disk->exists($trashedPathB));
    }

    public function test_purge_removes_files_only_after_the_database_commit(): void
    {
        $disk = Storage::disk(ContentAttachmentService::DISK);
        [$item, $path] = $this->itemWithFile($this->project);
        $item->delete();

        try {
            DB::transaction(function () use ($item): void {
                app(ContentTrashService::class)->purge($item);
                throw new \RuntimeException('Rolled back after the purge.');
            });
        } catch (\RuntimeException) {
        }

        // The rollback restored the rows, so the file must still be there.
        $this->assertSoftDeleted('content_items', ['id' => $item->id]);
        $this->assertTrue($disk->exists($path));

        app(ContentTrashService::class)->purge($item->fresh());
        $this->assertDatabaseMissing('content_items', ['id' => $item->id]);
        $this->assertFalse($disk->exists($path));
    }

    public function test_field_schema_answers_foreign_and_unknown_ids_identically(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $foreign = ContentProject::factory()->create();
        $foreignSection = ContentFieldSection::factory()->create(['content_project_id' => $foreign->id]);
        $foreignField = ContentFieldDefinition::factory()->create(['content_project_id' => $foreign->id]);
        $foreignOption = ContentFieldOption::factory()->for($foreignField, 'definition')->create();
        $ownField = ContentFieldDefinition::factory()->create([
            'content_project_id' => $this->project->id,
            'type' => ContentFieldType::SingleSelection,
        ]);
        $unknown = 999999;

        $responses = [];
        foreach ([$foreignSection->id, $unknown] as $id) {
            $responses['section'][] = $this->putJson(route('content.fields.sections.update', [$this->project, $id]), ['name' => 'Robada']);
            $responses['field'][] = $this->putJson(route('content.fields.update', [$this->project, $id === $unknown ? $unknown : $foreignField->id]), ['label' => 'Robado']);
            $responses['option'][] = $this->putJson(route('content.fields.options.update', [$this->project, $ownField->id, $id === $unknown ? $unknown : $foreignOption->id]), ['label' => 'Robada']);
            $responses['target'][] = $this->postJson(route('content.fields.store', $this->project), [
                'label' => 'Campo', 'type' => ContentFieldType::Text->value, 'sort' => 0,
                'content_field_section_id' => $id,
            ]);
        }

        foreach (['section', 'field', 'option'] as $kind) {
            [$foreignResponse, $unknownResponse] = $responses[$kind];
            $foreignResponse->assertNotFound();
            $unknownResponse->assertNotFound();
            $this->assertSame($foreignResponse->json('message'), $unknownResponse->json('message'), $kind);
        }

        [$foreignTarget, $unknownTarget] = $responses['target'];
        $foreignTarget->assertUnprocessable()->assertJsonValidationErrors('content_field_section_id');
        $unknownTarget->assertUnprocessable()->assertJsonValidationErrors('content_field_section_id');
        $this->assertSame($foreignTarget->json('errors'), $unknownTarget->json('errors'));

        $this->assertNotSame('Robada', $foreignSection->fresh()->name);
        $this->assertSame(1, ContentFieldDefinition::query()->where('content_project_id', $this->project->id)->count());
    }

    public function test_the_projects_index_links_the_consolidated_calendar_only_for_cross_project_viewers(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('content.projects.index'))->assertOk()
            ->assertSee(route('content.calendar.consolidated'), false);
        $this->actingAs($this->member)->get(route('content.projects.index'))->assertOk()
            ->assertDontSee(route('content.calendar.consolidated'), false);
    }

    private function vendedor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');

        return $user;
    }

    /**
     * @return array{0: ContentItem, 1: string}
     */
    private function itemWithFile(ContentProject $project, bool $idea = false): array
    {
        $factory = ContentItem::factory()->for($project, 'project');
        $item = ($idea ? $factory->idea() : $factory)->create();
        $attachment = app(ContentAttachmentService::class)->upload(
            $item,
            UploadedFile::fake()->createWithContent('foto.jpg', "\xFF\xD8\xFF".str_repeat("\x00", 64)),
        );

        return [$item, (string) $attachment->path];
    }
}
