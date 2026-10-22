<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentAttachmentService;
use App\Services\Content\ContentFieldValueService;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Findings raised while writing the user guide of the content module
 * (guide-findings-fixes T1, S5-S11): trash date format, the archived notice on
 * every project page, truthful trash and attachment wording, restoring a
 * retired attachment, retired custom field options, the inactive project
 * filter and consistent deactivation wording.
 */
class ContentGuideFindingsTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        Storage::fake(ContentAttachmentService::DISK);

        $this->project = ContentProject::factory()->create(['name' => 'Proyecto Guía']);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole('vendedor');
        $this->project->members()->attach($this->member);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    // ---------------------------------------------------------------- S5

    public function test_the_trash_shows_the_planned_date_as_day_month_year_and_time(): void
    {
        // 15:30 UTC is 10:30 in Peru.
        $item = ContentItem::factory()->for($this->project, 'project')->create(['planned_at' => '2026-03-08 15:30:00']);
        $item->delete();

        $this->actingAs($this->member)->get(route('content.projects.contents.trash', $this->project))->assertOk()
            ->assertSee('data-testid="content-trash-planned-at-'.$item->id.'"', false)
            ->assertSee('08/03/2026 10:30')
            ->assertDontSee('2026-03-08');
    }

    // ---------------------------------------------------------------- S6

    public function test_the_archived_notice_shows_on_the_grid_and_on_content_and_idea_pages(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $idea = ContentItem::factory()->for($this->project, 'project')->idea()->create();
        $notice = 'data-testid="content-project-read-only-notice"';

        $pages = [
            route('content.projects.contents.index', $this->project),
            route('content.projects.contents.show', [$this->project, $content]),
            route('content.projects.ideas.index', $this->project),
            route('content.projects.ideas.show', [$this->project, $idea]),
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->member)->get($page)->assertOk()->assertDontSee($notice, false);
        }

        $this->project->archive();

        foreach ($pages as $page) {
            $this->actingAs($this->member)->get($page)->assertOk()->assertSee($notice, false);
        }
    }

    // ---------------------------------------------------------------- S7

    public function test_the_move_to_trash_confirmation_does_not_reserve_emptying_to_an_administrator(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();

        $this->actingAs($this->member)->get(route('content.projects.contents.show', [$this->project, $content]))->assertOk()
            ->assertSee('data-testid="btn-trash-content-item"', false)
            ->assertDontSee('administrador')
            ->assertSee('hasta que se elimine definitivamente o se vacíe la papelera');
    }

    // ---------------------------------------------------------------- S8

    public function test_a_member_restores_a_retired_attachment_from_the_content_detail(): void
    {
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $attachment = ContentAttachment::factory()->for($content, 'content')->create(['original_name' => 'portada.jpg']);
        $this->actingAs($this->member);

        $this->post(route('content.projects.contents.attachments.retire', [$this->project, $content, $attachment]))
            ->assertRedirect(route('content.projects.contents.show', [$this->project, $content]))
            ->assertSessionHas('status', fn (string $status): bool => ! str_contains($status, 'con el contenido')
                && str_contains($status, 'Restaurar adjunto'));
        $this->assertSoftDeleted('content_attachments', ['id' => $attachment->id]);

        $this->get(route('content.projects.contents.show', [$this->project, $content]))->assertOk()
            ->assertSee('data-testid="content-attachments-retired"', false)
            ->assertSee('data-testid="content-attachment-restore-'.$attachment->id.'"', false)
            ->assertSee('Restaurar adjunto')
            ->assertDontSee('podrá restaurarse con el contenido');

        $this->post(route('content.projects.contents.attachments.restore', [$this->project, $content, $attachment]))
            ->assertRedirect(route('content.projects.contents.show', [$this->project, $content]))
            ->assertSessionHas('status');
        $this->assertNotSoftDeleted('content_attachments', ['id' => $attachment->id]);

        // An active attachment is not restorable: 404 like any unknown id.
        $this->post(route('content.projects.contents.attachments.restore', [$this->project, $content, $attachment]))
            ->assertNotFound();
    }

    public function test_restoring_an_attachment_respects_the_limit_and_the_archived_read_only_rule(): void
    {
        config(['content.attachments.max_per_content' => 1]);
        $content = ContentItem::factory()->for($this->project, 'project')->create();
        $retired = ContentAttachment::factory()->for($content, 'content')->create();
        $retired->retire();
        ContentAttachment::factory()->for($content, 'content')->link()->create();

        $this->actingAs($this->member)
            ->from(route('content.projects.contents.show', [$this->project, $content]))
            ->post(route('content.projects.contents.attachments.restore', [$this->project, $content, $retired]))
            ->assertSessionHasErrors('attachment');
        $this->assertSoftDeleted('content_attachments', ['id' => $retired->id]);

        config(['content.attachments.max_per_content' => 10]);
        $this->project->archive();

        $this->actingAs($this->member)->get(route('content.projects.contents.show', [$this->project, $content]))->assertOk()
            ->assertDontSee('data-testid="content-attachment-restore-'.$retired->id.'"', false);
        $this->actingAs($this->admin)
            ->postJson(route('content.projects.contents.attachments.restore', [$this->project, $content, $retired]))
            ->assertForbidden();
        $this->assertSoftDeleted('content_attachments', ['id' => $retired->id]);

        // A foreign project's attachment is a 404.
        $foreign = ContentItem::factory()->for(ContentProject::factory(), 'project')->create();
        $foreignAttachment = ContentAttachment::factory()->for($foreign, 'content')->create();
        $foreignAttachment->retire();
        $this->project->reactivate();
        $this->actingAs($this->member)
            ->post(route('content.projects.contents.attachments.restore', [$this->project, $content, $foreignAttachment]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- S9

    public function test_retired_custom_field_options_are_not_offered_on_new_contents_or_ideas(): void
    {
        [$single, $multi, $active, $retired] = $this->selectionFields();
        $this->actingAs($this->member);

        foreach ([route('content.projects.contents.create', $this->project), route('content.projects.ideas.create', $this->project)] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('Opción vigente')
                ->assertDontSee('Opción jubilada')
                ->assertDontSee('Lista jubilada')
                ->assertDontSee('(Retirada)');
        }

        $this->post(route('content.projects.contents.store', $this->project), $this->contentPayload([
            'fields' => [$single->key => (string) $retired[$single->key]->id],
        ]))->assertSessionHasErrors('fields.'.$single->key);
        $this->post(route('content.projects.ideas.store', $this->project), [
            'title' => 'Idea con opción retirada',
            'body' => 'Texto.',
            'fields' => [$multi->key => ['', (string) $active[$multi->key]->id, (string) $retired[$multi->key]->id]],
        ])->assertSessionHasErrors('fields.'.$multi->key);
        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_an_item_that_already_stores_a_retired_option_keeps_it_marked_retired(): void
    {
        [$single, $multi, $active, $retired] = $this->selectionFields();
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $values = app(ContentFieldValueService::class);
        $retired[$single->key]->forceFill(['is_active' => true])->save();
        $retired[$multi->key]->forceFill(['is_active' => true])->save();
        $values->store($item, $single, $retired[$single->key]->id);
        $values->store($item, $multi, [$active[$multi->key]->id, $retired[$multi->key]->id]);
        $retired[$single->key]->forceFill(['is_active' => false])->save();
        $retired[$multi->key]->forceFill(['is_active' => false])->save();
        $this->actingAs($this->member);

        $this->get(route('content.projects.contents.edit', [$this->project, $item]))->assertOk()
            ->assertSee('Opción jubilada (Retirada)');

        // Re-submitting the stored retired options is accepted.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->contentPayload([
            'revision' => $item->fresh()->revision,
            'fields' => [
                $single->key => (string) $retired[$single->key]->id,
                $multi->key => ['', (string) $active[$multi->key]->id, (string) $retired[$multi->key]->id],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $stored = $values->values($item->fresh());
        $this->assertSame($retired[$single->key]->id, (int) $stored[$single->key]);
        $this->assertEqualsCanonicalizing(
            [$active[$multi->key]->id, $retired[$multi->key]->id],
            array_map('intval', $stored[$multi->key]),
        );

        // Once dropped, the retired option cannot be chosen again.
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->contentPayload([
            'revision' => $item->fresh()->revision,
            'fields' => [$single->key => (string) $active[$single->key]->id, $multi->key => ['']],
        ]))->assertSessionHasNoErrors();
        $this->put(route('content.projects.contents.update', [$this->project, $item]), $this->contentPayload([
            'revision' => $item->fresh()->revision,
            'fields' => [$single->key => (string) $retired[$single->key]->id],
        ]))->assertSessionHasErrors('fields.'.$single->key);
    }

    public function test_a_duplicate_copies_a_stored_retired_option_as_it_is(): void
    {
        [$single, , , $retired] = $this->selectionFields();
        $item = ContentItem::factory()->for($this->project, 'project')->create();
        $values = app(ContentFieldValueService::class);
        $retired[$single->key]->forceFill(['is_active' => true])->save();
        $values->store($item, $single, $retired[$single->key]->id);
        $retired[$single->key]->forceFill(['is_active' => false])->save();

        $this->actingAs($this->member)
            ->post(route('content.projects.contents.duplicate', [$this->project, $item]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $copy = ContentItem::query()->whereKeyNot($item->id)->sole();
        $this->assertSame($retired[$single->key]->id, (int) $values->values($copy)[$single->key]);
    }

    // ---------------------------------------------------------------- S10

    public function test_archived_and_inactive_projects_have_separate_filters(): void
    {
        $inactive = ContentProject::factory()->create(['name' => 'Proyecto Pausado', 'is_active' => false]);
        $archived = ContentProject::factory()->archived()->create(['name' => 'Proyecto Cerrado']);
        $inactive->members()->attach($this->member);
        $archived->members()->attach($this->member);
        $this->actingAs($this->member);

        $this->get(route('content.projects.index'))->assertOk()
            ->assertSee('Proyecto Guía')
            ->assertDontSee('Proyecto Pausado')
            ->assertDontSee('Proyecto Cerrado')
            ->assertSee('Incluir archivados')
            ->assertSee('Incluir inactivos');

        $this->get(route('content.projects.index', ['include_archived' => 1]))->assertOk()
            ->assertSee('Proyecto Guía')
            ->assertSee('Proyecto Cerrado')
            ->assertDontSee('Proyecto Pausado');

        $this->get(route('content.projects.index', ['include_inactive' => 1]))->assertOk()
            ->assertSee('Proyecto Guía')
            ->assertSee('Proyecto Pausado')
            ->assertDontSee('Proyecto Cerrado');

        $this->get(route('content.projects.index', ['include_archived' => 1, 'include_inactive' => 1]))->assertOk()
            ->assertSee('Proyecto Pausado')
            ->assertSee('Proyecto Cerrado');
    }

    // ---------------------------------------------------------------- S11

    public function test_deactivation_wording_is_consistent_across_catalogs_sections_fields_and_options(): void
    {
        $section = ContentFieldSection::factory()->create(['content_project_id' => $this->project->id, 'name' => 'Sección pausada']);
        $field = ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::SingleSelection)->create(['label' => 'Campo pausado']);
        $option = ContentFieldOption::factory()->for($field, 'definition')->create(['label' => 'Opción pausada']);
        $objective = ContentObjective::factory()->for($this->project, 'project')->create(['name' => 'Objetivo pausado']);
        $this->actingAs($this->admin);

        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('Desactivar')
            ->assertDontSee('>Retirar<', false);

        $this->post(route('content.fields.sections.state', [$this->project, $section->id]), ['is_active' => false])
            ->assertSessionHas('status', 'Sección desactivada. Sus campos y referencias históricas se conservan.');
        $this->post(route('content.fields.state', [$this->project, $field->id]), ['is_active' => false])
            ->assertSessionHas('status', 'Campo desactivado. Sus valores registrados se conservan.');
        $this->post(route('content.fields.options.state', [$this->project, $field->id, $option->id]), ['is_active' => false])
            ->assertSessionHas('status', 'Opción desactivada. Sus referencias históricas se conservan.');
        $this->post(route('content.catalogs.state', [$this->project, 'objectives', $objective->id]), ['is_active' => false])
            ->assertSessionHas('status', 'Opción desactivada. Sus referencias históricas se conservan.');

        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('Inactiva')
            ->assertSee('Inactivo')
            ->assertSee('Reactivar')
            ->assertDontSee('Retirada')
            ->assertDontSee('Retirado');
        $this->get(route('content.catalogs.index', $this->project))->assertOk()
            ->assertSee('Inactiva')
            ->assertSee('Reactivar')
            ->assertDontSee('Retirada')
            ->assertDontSee('Retirado');
    }

    /**
     * A single and a multiple selection field, each with one active and one
     * retired option, plus a retired selection field.
     *
     * @return array{0: ContentFieldDefinition, 1: ContentFieldDefinition, 2: array<string, ContentFieldOption>, 3: array<string, ContentFieldOption>}
     */
    private function selectionFields(): array
    {
        $active = [];
        $retired = [];
        $fields = [];

        foreach ([ContentFieldType::SingleSelection, ContentFieldType::MultipleSelection] as $type) {
            $field = ContentFieldDefinition::factory()->for($this->project, 'project')->ofType($type)->create();
            $active[$field->key] = ContentFieldOption::factory()->for($field, 'definition')->create(['label' => 'Opción vigente', 'sort' => 1]);
            $retired[$field->key] = ContentFieldOption::factory()->for($field, 'definition')->create(['label' => 'Opción jubilada', 'sort' => 2, 'is_active' => false]);
            $fields[] = $field;
        }

        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::SingleSelection)->create(['label' => 'Lista jubilada', 'is_active' => false]);

        return [$fields[0], $fields[1], $active, $retired];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function contentPayload(array $overrides = []): array
    {
        return array_merge([
            'planned_date' => '2026-07-15',
            'planned_time' => '09:30',
            'status' => ContentItemStatus::Incomplete->value,
            'title' => 'Contenido con campos',
            'body' => 'Texto.',
        ], $overrides);
    }
}
