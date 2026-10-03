<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T9 — the important-dates calendar HTTP surface and its authorization.
 *
 * Every actor here is a NON-admin on purpose: the seeded `admin` role
 * short-circuits every Gate check through `AuthServiceProvider`'s `Gate::before`
 * bypass, so an assertion made as admin would prove nothing about the boundary.
 *
 * The two invariants this surface must never break:
 * - A project the actor may not reach answers 404, never 403, so a member cannot
 *   read a foreign calendar even by direct URL.
 * - The consolidated view is gated by the cross-project reading permission
 *   (`content_projects.view_all`) and its project filter can only narrow what
 *   that permission already allows.
 */
class ContentCalendarHttpTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        $this->project = ContentProject::factory()->create(['name' => 'Maia Consultores', 'color' => '#123456']);
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->manager);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-07-28',
            'name' => 'Fiestas patrias',
            'notes' => 'Publicación alusiva.',
        ], $overrides);
    }

    public function test_a_member_reaches_the_calendar_from_the_project_page(): void
    {
        $this->actingAs($this->manager)
            ->get(route('content.projects.show', $this->project))
            ->assertOk()
            ->assertSee(route('content.projects.calendar', $this->project), false);

        $this->actingAs($this->manager)
            ->get(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-02']))
            ->assertOk()
            ->assertSee('Fechas importantes de Maia Consultores')
            ->assertSee('Lun')
            ->assertSee('Dom')
            ->assertSee('febrero 2026')
            // February 2026 starts on the leading day 26 of January and ends on
            // 1 March, so the grid covers the neighbouring months.
            ->assertSee('content-calendar-cell-2026-01-26', false)
            ->assertSee('content-calendar-cell-2026-03-01', false)
            ->assertSee('content-calendar-form', false);
    }

    public function test_several_annotations_can_share_one_date_and_are_all_shown(): void
    {
        ContentCalendarAnnotation::factory()->for($this->project, 'project')->onDate('2026-07-28')->create(['name' => 'Aniversario patrio']);
        ContentCalendarAnnotation::factory()->for($this->project, 'project')->onDate('2026-07-28')->create(['name' => 'Actividad institucional']);

        $this->actingAs($this->manager)
            ->get(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('Aniversario patrio')
            ->assertSee('Actividad institucional');

        $this->assertSame(
            2,
            ContentCalendarAnnotation::query()
                ->where('content_project_id', $this->project->id)
                ->whereDate('annotated_at', '>=', '2026-07-28')
                ->count(),
        );
    }

    public function test_an_annotation_is_created_edited_and_removed(): void
    {
        $this->actingAs($this->manager)
            ->post(route('content.projects.calendar.annotations.store', $this->project), $this->payload())
            ->assertRedirect(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-07']));

        $annotation = ContentCalendarAnnotation::query()->where('content_project_id', $this->project->id)->firstOrFail();
        $this->assertSame('Fiestas patrias', $annotation->name);
        $this->assertSame('2026-07-28', $annotation->annotatedDate());
        $this->assertSame($this->manager->id, $annotation->created_by);

        $this->actingAs($this->manager)
            ->put(route('content.projects.calendar.annotations.update', [$this->project, $annotation]), $this->payload([
                'date' => '2026-07-29',
                'name' => 'Fiestas patrias (corregido)',
                'notes' => null,
            ]))
            ->assertRedirect(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-07']));

        $annotation->refresh();
        $this->assertSame('Fiestas patrias (corregido)', $annotation->name);
        $this->assertSame('2026-07-29', $annotation->annotatedDate());
        $this->assertNull($annotation->notes);

        $this->actingAs($this->manager)
            ->delete(route('content.projects.calendar.annotations.destroy', [$this->project, $annotation]))
            ->assertRedirect();

        $this->assertSoftDeleted('content_calendar_annotations', ['id' => $annotation->id]);
        $this->assertSame(0, ContentCalendarAnnotation::query()->count());
    }

    public function test_a_mutation_from_a_user_without_update_is_refused_and_changes_nothing(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);

        $existing = ContentCalendarAnnotation::factory()->for($this->project, 'project')->onDate('2026-07-28')->create(['name' => 'Original']);

        $this->actingAs($reader)->get(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-07']))->assertOk();

        $this->actingAs($reader)
            ->postJson(route('content.projects.calendar.annotations.store', $this->project), $this->payload())
            ->assertForbidden();

        $this->actingAs($reader)
            ->putJson(route('content.projects.calendar.annotations.update', [$this->project, $existing]), $this->payload(['name' => 'Robado']))
            ->assertForbidden();

        $this->actingAs($reader)
            ->deleteJson(route('content.projects.calendar.annotations.destroy', [$this->project, $existing]))
            ->assertForbidden();

        $this->assertSame(1, ContentCalendarAnnotation::query()->count());
        $this->assertSame('Original', $existing->fresh()->name);
    }

    public function test_a_non_member_gets_404_on_a_foreign_calendar_and_annotation(): void
    {
        $foreign = ContentProject::factory()->create(['name' => 'Maia Plagas']);
        $foreignAnnotation = ContentCalendarAnnotation::factory()->for($foreign, 'project')->onDate('2026-07-28')->create(['name' => 'Ajena']);

        // The actor is a member of $this->project but not of $foreign.
        $this->actingAs($this->manager);

        // Direct URL to the foreign calendar: 404, never 403, so existence is not disclosed.
        $this->get(route('content.projects.calendar', ['contentProject' => $foreign, 'month' => '2026-07']))->assertNotFound();

        // Every foreign annotation mutation is a 404 before validation.
        $this->postJson(route('content.projects.calendar.annotations.store', $foreign), $this->payload())->assertNotFound();
        $this->putJson(route('content.projects.calendar.annotations.update', [$foreign, $foreignAnnotation]), $this->payload())->assertNotFound();
        $this->deleteJson(route('content.projects.calendar.annotations.destroy', [$foreign, $foreignAnnotation]))->assertNotFound();

        // Even under the actor's own project, a foreign annotation id cannot be
        // read: it is resolved inside the addressed project.
        $this->get(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-07', 'edit' => $foreignAnnotation->id]))
            ->assertNotFound();
        $this->putJson(route('content.projects.calendar.annotations.update', [$this->project, $foreignAnnotation]), $this->payload())
            ->assertNotFound();

        $this->assertSame(1, ContentCalendarAnnotation::query()->count());
        $this->assertSame('Ajena', $foreignAnnotation->fresh()->name);
    }

    public function test_the_consolidated_view_is_forbidden_without_the_cross_project_permission(): void
    {
        $this->assertFalse($this->manager->hasRole('admin'));

        // A member with read access to their own project still cannot open the
        // consolidated cross-project view.
        $this->actingAs($this->manager)
            ->get(route('content.calendar.consolidated', ['month' => '2026-07']))
            ->assertForbidden();
    }

    public function test_the_consolidated_view_shows_only_the_selected_projects(): void
    {
        $plagas = ContentProject::factory()->create(['name' => 'Maia Plagas', 'color' => '#00AA00']);
        $residuos = ContentProject::factory()->create(['name' => 'Maia Residuos', 'color' => '#AA0000']);

        ContentCalendarAnnotation::factory()->for($this->project, 'project')->onDate('2026-07-28')->create(['name' => 'Evento Consultores']);
        ContentCalendarAnnotation::factory()->for($plagas, 'project')->onDate('2026-07-28')->create(['name' => 'Evento Plagas']);
        ContentCalendarAnnotation::factory()->for($residuos, 'project')->onDate('2026-07-28')->create(['name' => 'Evento Residuos']);

        // A non-admin user holding the cross-project reading permission.
        $administrator = User::factory()->create(['is_active' => true]);
        $administrator->givePermissionTo(['content_projects.view', 'content_projects.view_all']);
        $this->assertFalse($administrator->hasRole('admin'));

        // All projects.
        $this->actingAs($administrator)
            ->get(route('content.calendar.consolidated', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Evento Consultores')
            ->assertSee('Evento Plagas')
            ->assertSee('Evento Residuos')
            ->assertSee('julio 2026')
            ->assertSee('content-consolidated-project-'.$this->project->id, false)
            ->assertSee('#123456', false);

        // Exactly one project.
        $this->actingAs($administrator)
            ->get(route('content.calendar.consolidated', ['month' => '2026-07', 'projects' => [$plagas->id]]))
            ->assertOk()
            ->assertSee('Evento Plagas')
            ->assertDontSee('Evento Consultores')
            ->assertDontSee('Evento Residuos');

        // Several projects.
        $this->actingAs($administrator)
            ->get(route('content.calendar.consolidated', ['month' => '2026-07', 'projects' => [$this->project->id, $residuos->id]]))
            ->assertOk()
            ->assertSee('Evento Consultores')
            ->assertSee('Evento Residuos')
            ->assertDontSee('Evento Plagas');

        // A month with no annotations renders the empty state.
        $this->actingAs($administrator)
            ->get(route('content.calendar.consolidated', ['month' => '2026-01']))
            ->assertOk()
            ->assertSee('content-consolidated-empty', false);
    }

    public function test_the_calendar_renders_annotations_and_planned_contents_on_the_peru_day(): void
    {
        // 2026-03-09 02:00 UTC is 2026-03-08 21:00 in Peru.
        ContentCalendarAnnotation::factory()->for($this->project, 'project')
            ->atUtc('2026-03-09 02:00:00')
            ->create(['name' => 'Aniversario nocturno']);

        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 23:30:00'),
            'title' => 'Publicación de la noche',
        ]);

        $this->actingAs($this->manager)
            ->get(route('content.projects.calendar', ['contentProject' => $this->project, 'month' => '2026-03']))
            ->assertOk()
            // Both appear after the 8th's cell marker and before the 9th's, so
            // they are bucketed on the Peruvian day, not the UTC day.
            ->assertSeeInOrder([
                'content-calendar-cell-2026-03-08',
                'Aniversario nocturno',
                'Publicación de la noche',
                'content-calendar-cell-2026-03-09',
            ], false);
    }
}
