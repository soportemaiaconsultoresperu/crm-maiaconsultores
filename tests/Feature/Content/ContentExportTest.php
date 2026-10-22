<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentFieldValueService;
use App\Services\Content\ContentGridExportService;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * T10 — grid exports to Excel and PDF.
 *
 * The exports must answer exactly the same filtered set the grid screen shows,
 * must never leak a row from another project, and must neutralise any cell a
 * user typed so a spreadsheet can never execute it.
 *
 * Parity with the grid is proven by observation, not assumed: each parity test
 * first reads the grid HTML for a filter and then reads the exported file for
 * the SAME filter, asserting the same row is present and the out-of-filter row
 * is absent from both.
 */
class ContentExportTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        $this->project = ContentProject::factory()->create();
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->manager);

        $this->actingAs($this->manager);
    }

    public function test_excel_export_contains_exactly_the_rows_the_same_filter_shows_in_the_grid(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-15 10:00:00'),
            'title' => 'Contenido marzo visible',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-04-15 10:00:00'),
            'title' => 'Contenido abril fuera',
        ]);

        // What the operator sees on screen with the month filter.
        $this->get(route('content.projects.contents.index', ['contentProject' => $this->project, 'month' => '2026-03']))
            ->assertOk()
            ->assertSee('Contenido marzo visible')
            ->assertDontSee('Contenido abril fuera');

        // The same filter through the Excel export must match that set exactly.
        $response = $this->get(route('content.projects.contents.export.excel', [
            'contentProject' => $this->project,
            'month' => '2026-03',
        ]));
        $response->assertOk();

        $rows = $this->excelRows($response);
        $flat = $this->flatten($rows);

        $this->assertStringContainsString('Contenido marzo visible', $flat);
        $this->assertStringNotContainsString('Contenido abril fuera', $flat);

        // Exactly the heading row plus the one in-range data row.
        $this->assertCount(2, $rows);
    }

    public function test_export_never_contains_another_projects_content_even_when_the_filter_names_it(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignPillar = ContentPillar::factory()->for($foreign, 'project')->create();
        ContentItem::factory()->for($foreign, 'project')->create([
            'content_pillar_id' => $foreignPillar->id,
            'title' => 'Contenido de otro proyecto',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'title' => 'Contenido propio',
        ]);

        // An unfiltered export of the addressed project already excludes the
        // foreign row...
        $unfiltered = $this->flatten($this->excelRows(
            $this->get(route('content.projects.contents.export.excel', $this->project)),
        ));
        $this->assertStringContainsString('Contenido propio', $unfiltered);
        $this->assertStringNotContainsString('Contenido de otro proyecto', $unfiltered);

        // ...and a filter that names the foreign project's pillar can only
        // narrow this project's rows, never widen the export to the other one.
        $filtered = $this->flatten($this->excelRows(
            $this->get(route('content.projects.contents.export.excel', [
                'contentProject' => $this->project,
                'pillar' => (string) $foreignPillar->id,
            ])),
        ));
        $this->assertStringNotContainsString('Contenido de otro proyecto', $filtered);
        $this->assertStringNotContainsString('Contenido propio', $filtered);
    }

    public function test_a_custom_field_appears_as_a_column_with_its_values(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)
            ->create(['key' => 'resumen', 'label' => 'Resumen editorial']);

        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Con campo propio']);
        app(ContentFieldValueService::class)->save($item, ['resumen' => 'Valor del campo']);

        $rows = $this->excelRows($this->get(route('content.projects.contents.export.excel', $this->project)));

        $headings = array_map(fn ($cell): string => (string) $cell, $rows[0]);
        $this->assertContains('Resumen editorial', $headings);

        $resumenColumn = array_search('Resumen editorial', $headings, true);
        $this->assertIsInt($resumenColumn);
        $this->assertSame('Valor del campo', (string) $rows[1][$resumenColumn]);
    }

    public function test_excel_export_neutralises_cells_that_start_with_a_formula_character(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create([
            'title' => '=SUM(1,1)',
            'body' => '@cmd',
            'hashtags' => '+1',
            'notes' => '-2+3',
        ]);

        $flat = $this->flatten($this->excelRows(
            $this->get(route('content.projects.contents.export.excel', $this->project)),
        ));

        $this->assertStringContainsString("'=SUM(1,1)", $flat);
        $this->assertStringContainsString("'@cmd", $flat);
        $this->assertStringContainsString("'+1", $flat);
        $this->assertStringContainsString("'-2+3", $flat);
    }

    public function test_pdf_export_returns_a_document_and_neutralises_formula_like_cells(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-15 10:00:00'),
            'title' => '=SUM(1,1)',
        ]);

        $response = $this->get(route('content.projects.contents.export.pdf', [
            'contentProject' => $this->project,
            'month' => '2026-03',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        // Dompdf compresses and encodes its content stream, so the text is not
        // observable in the raw bytes. The neutralisation is proven against the
        // document view the action renders, fed with the exact heading and row
        // set the service produces for the same filter (the PDF action does not
        // re-derive the cells).
        $export = app(ContentGridExportService::class);
        $view = view('content.exports.pdf', [
            'project' => $this->project,
            'headings' => $export->headings($this->project),
            'rows' => $export->rows($this->project, ['month' => '2026-03']),
            'filterSummary' => $export->filterSummary($this->project, ['month' => '2026-03']),
            'generatedAt' => '01/01/2026 00:00',
        ])->render();

        $this->assertStringContainsString("'=SUM(1,1)", html_entity_decode(strip_tags($view), ENT_QUOTES, 'UTF-8'));
    }

    public function test_the_pdf_document_states_the_project_and_the_applied_filters(): void
    {
        $export = app(ContentGridExportService::class);

        $html = view('content.exports.pdf', [
            'project' => $this->project,
            'headings' => $export->headings($this->project),
            'rows' => $export->rows($this->project, ['month' => '2026-03']),
            'filterSummary' => $export->filterSummary($this->project, ['month' => '2026-03']),
            'generatedAt' => '01/01/2026 00:00',
        ])->render();

        $this->assertStringContainsString($this->project->name, $html);
        $this->assertStringContainsString('Filtros aplicados', $html);
        $this->assertStringContainsString('Mes: 2026-03', $html);
        // Landscape so a wide grid is not cut off.
        $this->assertStringContainsString('size: A4 landscape', $html);
    }

    public function test_pdf_export_renders_a_multi_page_grid_without_error(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            ContentItem::factory()->for($this->project, 'project')->create([
                'planned_at' => PeruTime::toUtc('2026-03-'.str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT).' 09:00:00'),
                'title' => 'Contenido paginado '.$i,
            ]);
        }

        $response = $this->get(route('content.projects.contents.export.pdf', $this->project));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_the_grid_offers_both_export_formats_carrying_the_active_filters(): void
    {
        $this->get(route('content.projects.contents.index', [
            'contentProject' => $this->project,
            'month' => '2026-03',
        ]))
            ->assertOk()
            ->assertSee('Exportar a Excel')
            ->assertSee('Exportar a PDF')
            ->assertSee(route('content.projects.contents.export.excel', $this->project).'?month=2026-03', false)
            ->assertSee(route('content.projects.contents.export.pdf', $this->project).'?month=2026-03', false);
    }

    public function test_a_read_only_member_can_export_because_an_export_is_a_read(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);

        $this->actingAs($reader)
            ->get(route('content.projects.contents.export.excel', $this->project))
            ->assertOk();

        $this->actingAs($reader)
            ->get(route('content.projects.contents.export.pdf', $this->project))
            ->assertOk();
    }

    public function test_a_user_who_cannot_reach_the_project_gets_404_on_both_export_routes(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->givePermissionTo('content_projects.view');

        $this->actingAs($stranger)
            ->get(route('content.projects.contents.export.excel', $this->project))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('content.projects.contents.export.pdf', $this->project))
            ->assertNotFound();
    }

    public function test_a_user_without_the_read_permission_is_refused(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->get(route('content.projects.contents.export.excel', $this->project))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('content.projects.contents.export.pdf', $this->project))
            ->assertNotFound();
    }

    /**
     * Read the exported .xlsx through Maatwebsite and return the sheet rows.
     *
     * @return list<list<mixed>>
     */
    private function excelRows(TestResponse $response): array
    {
        $reader = new class implements ToArray
        {
            /** @var list<list<mixed>> */
            public array $rows = [];

            public function array(array $array): void
            {
                $this->rows = $array;
            }
        };

        $tmp = tempnam(sys_get_temp_dir(), 'content_export_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheets = Excel::toArray($reader, $tmp);
        @unlink($tmp);

        // The styled export has a title block above the table; the table
        // starts at the heading row ("Fecha" is always the first column).
        $rows = $sheets[0] ?? [];
        foreach ($rows as $index => $row) {
            if (($row[0] ?? null) === 'Fecha') {
                return array_values(array_slice($rows, $index));
            }
        }

        return [];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function flatten(array $rows): string
    {
        return implode(' | ', array_map(
            fn (array $row): string => implode(' | ', array_map(fn ($cell): string => (string) $cell, $row)),
            $rows,
        ));
    }
}
