<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Support\Content\PeruTime;
use App\Support\Content\ProjectColor;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S1.1-S1.3 — the project colour as visual identity: picker and validation,
 * the shared project band, the list marker and the calendars.
 *
 * Rendering assertions use an admin actor because they prove presentation, not
 * the authorization boundary (covered by the HTTP tests of each surface).
 */
class ContentProjectColorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_the_helper_resolves_contrast_tint_and_neutral_default(): void
    {
        $this->assertSame('#1d4ed8', ProjectColor::normalize(' #1D4ED8 '));
        $this->assertNull(ProjectColor::normalize('red'));
        $this->assertNull(ProjectColor::normalize('#123'));
        $this->assertNull(ProjectColor::normalize(null));

        $this->assertSame(ProjectColor::DEFAULT, ProjectColor::resolve(null));
        $this->assertSame(ProjectColor::DEFAULT, ProjectColor::resolve('not-a-color'));
        $this->assertSame('#00aa00', ProjectColor::resolve('#00AA00'));

        $this->assertSame('#ffffff', ProjectColor::textColor('#1d4ed8'));
        $this->assertSame('#ffffff', ProjectColor::textColor('#000000'));
        $this->assertSame(ProjectColor::DARK_TEXT, ProjectColor::textColor('#facc15'));
        $this->assertSame(ProjectColor::DARK_TEXT, ProjectColor::textColor('#ffffff'));

        $this->assertSame('#ffffff', ProjectColor::tint('#000000', 0.0));
        $this->assertSame('#000000', ProjectColor::tint('#000000', 1.0));
        $this->assertSame(ProjectColor::DARK_TEXT, ProjectColor::textColor(ProjectColor::tint('#1d4ed8')));

        $this->assertCount(10, ProjectColor::PALETTE);
        foreach (array_keys(ProjectColor::PALETTE) as $hex) {
            $this->assertSame($hex, ProjectColor::normalize($hex));
        }
    }

    public function test_creating_a_project_rejects_malformed_colors_and_normalizes_valid_ones(): void
    {
        foreach (['red', '#12345', '#1234567', '123456', '#GGGGGG', 'rgb(0,0,0)'] as $bad) {
            $this->actingAs($this->admin)
                ->post(route('content.projects.store'), ['name' => 'Proyecto', 'slug' => 'proyecto', 'color' => $bad])
                ->assertSessionHasErrors('color');
        }

        $this->assertDatabaseCount('content_projects', 0);

        $this->actingAs($this->admin)
            ->post(route('content.projects.store'), ['name' => 'Con color', 'slug' => 'con-color', 'color' => '#A1B2C3'])
            ->assertSessionHasNoErrors();
        $this->assertSame('#a1b2c3', ContentProject::query()->where('slug', 'con-color')->value('color'));

        $this->actingAs($this->admin)
            ->post(route('content.projects.store'), ['name' => 'Sin color', 'slug' => 'sin-color', 'color' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull(ContentProject::query()->where('slug', 'sin-color')->value('color'));

        // The picker's "Personalizado" choice submits the native colour input.
        $this->actingAs($this->admin)
            ->post(route('content.projects.store'), [
                'name' => 'Personalizado', 'slug' => 'personalizado',
                'color' => 'custom', 'color_custom' => '#ABCDEF',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('#abcdef', ContentProject::query()->where('slug', 'personalizado')->value('color'));

        $this->actingAs($this->admin)
            ->post(route('content.projects.store'), [
                'name' => 'Personalizado malo', 'slug' => 'personalizado-malo',
                'color' => 'custom', 'color_custom' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('color');
    }

    public function test_updating_a_project_validates_the_color(): void
    {
        $project = ContentProject::factory()->create(['slug' => 'maia', 'color' => '#123456']);

        $this->actingAs($this->admin)
            ->put(route('content.projects.update', $project), ['name' => 'Maia', 'slug' => 'maia', 'color' => 'blue'])
            ->assertSessionHasErrors('color');
        $this->assertSame('#123456', $project->fresh()->color);

        $this->actingAs($this->admin)
            ->put(route('content.projects.update', $project), ['name' => 'Maia', 'slug' => 'maia', 'color' => '#DC2626'])
            ->assertSessionHasNoErrors();
        $this->assertSame('#dc2626', $project->fresh()->color);
    }

    public function test_the_form_offers_a_preset_palette_and_a_custom_picker(): void
    {
        $this->actingAs($this->admin)
            ->get(route('content.projects.create'))
            ->assertOk()
            ->assertSee('data-testid="content-project-color-palette"', false)
            ->assertSee('type="color"', false)
            ->assertSee('name="color_custom"', false)
            ->assertSee('Sin color')
            ->assertSee('Personalizado');

        // A preset colour is pre-selected on edit; a custom one fills the picker.
        $preset = array_key_first(ProjectColor::PALETTE);
        $project = ContentProject::factory()->create(['color' => $preset]);
        $this->actingAs($this->admin)
            ->get(route('content.projects.edit', $project))
            ->assertOk()
            ->assertSee('data-testid="content-project-color-option-'.ltrim($preset, '#').'" checked', false)
            ->assertSee('data-testid="content-project-band"', false);

        $custom = ContentProject::factory()->create(['color' => '#abcdef']);
        $this->actingAs($this->admin)
            ->get(route('content.projects.edit', $custom))
            ->assertOk()
            ->assertSee('value="#abcdef"', false)
            ->assertSee('data-testid="content-project-color-option-custom" checked', false);
    }

    public function test_the_project_band_shows_the_color_on_every_owned_project_page(): void
    {
        $project = ContentProject::factory()->create(['name' => 'Maia Plagas', 'color' => '#1D4ED8']);

        foreach ([
            route('content.projects.show', $project),
            route('content.projects.edit', $project),
            route('content.projects.calendar', $project),
            route('content.projects.contents.trash', $project),
            route('content.catalogs.index', $project),
        ] as $url) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertSee('data-testid="content-project-band"', false)
                ->assertSee('data-project-color="#1d4ed8"', false)
                ->assertSee('Maia Plagas');
        }
    }

    public function test_the_band_falls_back_to_the_neutral_color(): void
    {
        $project = ContentProject::factory()->create(['color' => null]);

        $this->actingAs($this->admin)
            ->get(route('content.projects.show', $project))
            ->assertOk()
            ->assertSee('data-project-color="'.ProjectColor::DEFAULT.'"', false);
    }

    public function test_the_projects_list_shows_a_colored_marker_per_project(): void
    {
        $colored = ContentProject::factory()->create(['color' => '#16A34A']);
        $plain = ContentProject::factory()->create(['color' => null]);

        $this->actingAs($this->admin)
            ->get(route('content.projects.index'))
            ->assertOk()
            ->assertSee('data-testid="content-project-marker-'.$colored->id.'"', false)
            ->assertSee('background-color: #16a34a', false)
            ->assertSee('data-testid="content-project-marker-'.$plain->id.'"', false)
            ->assertSee('background-color: '.ProjectColor::DEFAULT, false);
    }

    public function test_the_project_calendar_paints_planned_contents_with_the_project_color(): void
    {
        $project = ContentProject::factory()->create(['color' => '#DC2626']);
        $item = ContentItem::factory()->for($project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-10 10:00:00'),
            'title' => 'Post coloreado',
        ]);

        $tint = ProjectColor::tint('#dc2626');

        $this->actingAs($this->admin)
            ->get(route('content.projects.calendar', ['contentProject' => $project, 'month' => '2026-03']))
            ->assertOk()
            ->assertSee('data-testid="content-calendar-content-'.$item->id.'"', false)
            ->assertSee('background-color: '.$tint, false)
            ->assertSee('border-left-color: #dc2626', false)
            ->assertSee('Post coloreado');
    }

    public function test_the_consolidated_calendar_paints_each_project_and_shows_a_legend(): void
    {
        $plagas = ContentProject::factory()->create(['name' => 'Maia Plagas', 'color' => '#16A34A']);
        $residuos = ContentProject::factory()->create(['name' => 'Maia Residuos', 'color' => null]);
        $hidden = ContentProject::factory()->create(['name' => 'Maia Oculto', 'color' => '#7C3AED']);

        $annotation = ContentCalendarAnnotation::factory()->for($plagas, 'project')->onDate('2026-07-28')->create(['name' => 'Evento Plagas']);

        $response = $this->actingAs($this->admin)
            ->get(route('content.calendar.consolidated', ['month' => '2026-07', 'projects' => [$plagas->id, $residuos->id]]))
            ->assertOk()
            ->assertSee('data-testid="content-consolidated-legend"', false)
            ->assertSee('data-testid="content-consolidated-legend-'.$plagas->id.'"', false)
            ->assertSee('data-testid="content-consolidated-legend-'.$residuos->id.'"', false)
            ->assertDontSee('data-testid="content-consolidated-legend-'.$hidden->id.'"', false)
            ->assertSee('data-testid="content-consolidated-annotation-'.$annotation->id.'"', false)
            ->assertSee('border-left-color: #16a34a', false)
            ->assertSee('background-color: '.ProjectColor::tint('#16a34a'), false)
            ->assertSee('background-color: '.ProjectColor::DEFAULT, false);

        // Without a filter, the legend lists every visible project.
        $this->actingAs($this->admin)
            ->get(route('content.calendar.consolidated', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('data-testid="content-consolidated-legend-'.$hidden->id.'"', false);
    }
}
