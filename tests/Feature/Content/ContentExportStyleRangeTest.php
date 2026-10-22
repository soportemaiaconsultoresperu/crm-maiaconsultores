<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentGridExportService;
use App\Services\Content\ContentItemService;
use App\Support\Content\PeruTime;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * S1.4, S3, S4 (content-color-fields-export) — styled Excel, project-coloured
 * PDF, Desde/Hasta range on the grid and both exports, and the hidden standard
 * fields removed from the exported columns.
 */
class ContentExportStyleRangeTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);

        $this->project = ContentProject::factory()->create(['name' => 'Proyecto Andino', 'color' => '#1d4ed8']);
        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($manager);

        $this->actingAs($manager);
    }

    /**
     * Four contents around the Lima midnight edges of 10/03/2026 - 12/03/2026.
     */
    private function edgeContents(): void
    {
        $at = [
            'Antes del rango' => '2026-03-09 23:59:00',
            'Inicio del rango' => '2026-03-10 00:00:00',
            'Fin del rango' => '2026-03-12 23:59:00',
            'Después del rango' => '2026-03-13 00:00:00',
        ];

        foreach ($at as $title => $peru) {
            ContentItem::factory()->for($this->project, 'project')->create([
                'planned_at' => PeruTime::toUtc($peru),
                'title' => $title,
            ]);
        }
    }

    // S4 ---------------------------------------------------------------------

    public function test_the_grid_query_keeps_exactly_the_inclusive_lima_range(): void
    {
        $this->edgeContents();

        $titles = app(ContentItemService::class)
            ->query($this->project, ['from' => '2026-03-10', 'to' => '2026-03-12'])
            ->pluck('title')->sort()->values()->all();

        $this->assertSame(['Fin del rango', 'Inicio del rango'], $titles);
    }

    public function test_the_range_and_the_month_both_apply(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-02-27 10:00:00'), 'title' => 'Febrero',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-03 10:00:00'), 'title' => 'Marzo dentro',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-20 10:00:00'), 'title' => 'Marzo fuera',
        ]);

        $titles = app(ContentItemService::class)
            ->query($this->project, ['month' => '2026-03', 'from' => '2026-02-25', 'to' => '2026-03-05'])
            ->pluck('title')->all();

        $this->assertSame(['Marzo dentro'], $titles);
    }

    public function test_the_grid_screen_filters_by_the_range_and_shows_the_inputs(): void
    {
        $this->edgeContents();

        $this->get(route('content.projects.contents.index', [
            'contentProject' => $this->project, 'from' => '2026-03-10', 'to' => '2026-03-12',
        ]))
            ->assertOk()
            ->assertSee('Inicio del rango')
            ->assertSee('Fin del rango')
            ->assertDontSee('Antes del rango')
            ->assertDontSee('Después del rango')
            ->assertSee('Desde')
            ->assertSee('Hasta')
            ->assertSee('name="from" value="2026-03-10"', false)
            ->assertSee('name="to" value="2026-03-12"', false)
            ->assertSee(route('content.projects.contents.export.excel', $this->project).'?from=2026-03-10&amp;to=2026-03-12', false)
            ->assertSee(route('content.projects.contents.export.pdf', $this->project).'?from=2026-03-10&amp;to=2026-03-12', false);
    }

    public function test_the_excel_export_contains_exactly_the_range(): void
    {
        $this->edgeContents();

        $sheet = $this->sheet($this->get(route('content.projects.contents.export.excel', [
            'contentProject' => $this->project, 'from' => '2026-03-10', 'to' => '2026-03-12',
        ])));

        $titles = $this->columnValues($sheet, 'Gancho o título');
        sort($titles);
        $this->assertSame(['Fin del rango', 'Inicio del rango'], $titles);
        $this->assertStringContainsString('Desde 10/03/2026 hasta 12/03/2026', $this->titleBlock($sheet));
    }

    public function test_the_pdf_export_contains_exactly_the_range_and_states_it(): void
    {
        $this->edgeContents();
        $captured = $this->capturePdfData();

        $this->get(route('content.projects.contents.export.pdf', [
            'contentProject' => $this->project, 'from' => '2026-03-10', 'to' => '2026-03-12',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotEmpty($captured['headings'] ?? null);
        $titleIndex = array_search('Gancho o título', $captured['headings'], true);
        $titles = array_map(fn (array $row): string => (string) $row[$titleIndex], $captured['rows']);
        sort($titles);
        $this->assertSame(['Fin del rango', 'Inicio del rango'], $titles);
        $this->assertContains('Desde 10/03/2026 hasta 12/03/2026', $captured['filterSummary']);

        $html = view('content.exports.pdf', $captured->getArrayCopy())->render();
        $this->assertStringContainsString('Desde 10/03/2026 hasta 12/03/2026', $html);
    }

    public function test_an_invalid_range_never_breaks_the_grid_or_the_exports(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create();

        $cases = [
            'from=2026-02-30' => 'from',
            'from=10/03/2026' => 'from',
            'from=1999-12-31' => 'from',
            'to=2100-01-01' => 'to',
            'to=abc' => 'to',
            'from=2026-03-12&to=2026-03-10' => 'to',
        ];
        $routes = ['content.projects.contents.index', 'content.projects.contents.export.excel', 'content.projects.contents.export.pdf'];

        foreach ($cases as $query => $field) {
            foreach ($routes as $route) {
                $response = $this->get(route($route, $this->project).'?'.$query.'&q=hola');

                $this->assertNotSame(500, $response->getStatusCode(), $route.' '.$query);
                $response->assertRedirect();
                $this->assertStringStartsWith(route('content.projects.contents.index', $this->project), (string) $response->headers->get('Location'));
                $this->assertStringNotContainsString($field.'=', (string) $response->headers->get('Location'));
                $response->assertSessionHasErrors($field);
            }
        }

        $this->get(route('content.projects.contents.index', $this->project).'?from=2026-03-12&to=2026-03-10');
        $this->get(route('content.projects.contents.index', $this->project))
            ->assertOk()
            ->assertSee('La fecha «Hasta» no puede ser anterior a la fecha «Desde»');
    }

    public function test_the_query_ignores_an_impossible_date_instead_of_failing(): void
    {
        $item = ContentItem::factory()->for($this->project, 'project')->create();

        $ids = app(ContentItemService::class)
            ->query($this->project, ['from' => '2026-02-30', 'to' => 'abc'])
            ->pluck('id')->all();

        $this->assertSame([$item->id], $ids);
    }

    public function test_the_summary_states_an_open_range(): void
    {
        $export = app(ContentGridExportService::class);

        $this->assertContains('Desde 10/03/2026', $export->filterSummary($this->project, ['from' => '2026-03-10']));
        $this->assertContains('Hasta 12/03/2026', $export->filterSummary($this->project, ['to' => '2026-03-12']));
    }

    // S3 ---------------------------------------------------------------------

    public function test_the_excel_export_is_styled_with_the_project_colour_title_block_freeze_and_filter(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2020-01-10 09:00:00'),
            'status' => ContentItemStatus::InProgress,
            'title' => 'Pasado sin publicar',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2099-01-10 09:00:00'),
            'status' => ContentItemStatus::Incomplete,
            'title' => 'Futuro',
        ]);

        $sheet = $this->sheet($this->get(route('content.projects.contents.export.excel', $this->project)));
        $headerRow = $this->headerRow($sheet);
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = $headerRow + 2;

        // Title block above the table.
        $this->assertGreaterThan(1, $headerRow);
        $block = $this->titleBlock($sheet);
        $this->assertStringContainsString('Proyecto Andino', $block);
        $this->assertStringContainsString('Sin filtros aplicados', $block);
        $this->assertMatchesRegularExpression('/Generado el \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/', $block);

        // Header: bold, project colour, readable (white on dark blue) text.
        $header = $sheet->getStyle('A'.$headerRow);
        $this->assertTrue($header->getFont()->getBold());
        $this->assertSame('FF1D4ED8', strtoupper($header->getFill()->getStartColor()->getARGB()));
        $this->assertSame('FFFFFFFF', strtoupper($header->getFont()->getColor()->getARGB()));
        $this->assertTrue($sheet->getStyle($lastColumn.$headerRow)->getFont()->getBold());

        // Frozen below the header, filter on the table range.
        $this->assertSame('A'.($headerRow + 1), $sheet->getFreezePane());
        $this->assertSame('A'.$headerRow.':'.$lastColumn.$lastRow, $sheet->getAutoFilter()->getRange());

        // Long text wraps and has a sensible width.
        $copyColumn = $this->column($sheet, 'Descripción o copy');
        $this->assertTrue($sheet->getStyle($copyColumn.($headerRow + 1))->getAlignment()->getWrapText());
        $this->assertGreaterThanOrEqual(30, $sheet->getColumnDimension($copyColumn)->getWidth());

        // The Atrasado marker is highlighted only where present.
        $overdueColumn = $this->column($sheet, 'Atrasado');
        $titleColumn = $this->column($sheet, 'Gancho o título');
        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $style = $sheet->getStyle($overdueColumn.$row);
            if ($sheet->getCell($titleColumn.$row)->getValue() === 'Pasado sin publicar') {
                $this->assertSame('Atrasado', $sheet->getCell($overdueColumn.$row)->getValue());
                $this->assertSame('FFF8D7DA', strtoupper($style->getFill()->getStartColor()->getARGB()));
                $this->assertSame('FF842029', strtoupper($style->getFont()->getColor()->getARGB()));
            } else {
                $this->assertNotSame('FFF8D7DA', strtoupper($style->getFill()->getStartColor()->getARGB()));
            }
        }
    }

    public function test_a_light_project_colour_gets_dark_header_text_and_no_colour_falls_back(): void
    {
        $this->project->forceFill(['color' => '#ca8a04'])->save();
        $sheet = $this->sheet($this->get(route('content.projects.contents.export.excel', $this->project)));
        $header = $sheet->getStyle('A'.$this->headerRow($sheet));
        $this->assertSame('FFCA8A04', strtoupper($header->getFill()->getStartColor()->getARGB()));
        $this->assertSame('FF212529', strtoupper($header->getFont()->getColor()->getARGB()));

        $this->project->forceFill(['color' => null])->save();
        $sheet = $this->sheet($this->get(route('content.projects.contents.export.excel', $this->project)));
        $this->assertSame('FF6C757D', strtoupper($sheet->getStyle('A'.$this->headerRow($sheet))->getFill()->getStartColor()->getARGB()));
    }

    public function test_the_title_block_neutralises_a_formula_like_project_name(): void
    {
        $this->project->forceFill(['name' => '=HYPERLINK("http://evil.test")'])->save();

        $sheet = $this->sheet($this->get(route('content.projects.contents.export.excel', $this->project)));

        for ($row = 1; $row < $this->headerRow($sheet); $row++) {
            $value = (string) $sheet->getCell('A'.$row)->getValue();
            $this->assertFalse(str_starts_with($value, '='), $value);
        }
    }

    // S1.4 -------------------------------------------------------------------

    public function test_the_pdf_header_uses_the_project_colour_with_readable_text(): void
    {
        $export = app(ContentGridExportService::class);
        $html = view('content.exports.pdf', [
            'project' => $this->project,
            'headings' => $export->headings($this->project),
            'rows' => [],
            'filterSummary' => [],
            'generatedAt' => '01/01/2026 00:00',
        ])->render();

        $this->assertMatchesRegularExpression('/th\s*\{[^}]*background:\s*#1d4ed8;[^}]*color:\s*#ffffff;/s', $html);
        $this->assertStringNotContainsString('#2563eb', $html);
    }

    // S2 (exports) -----------------------------------------------------------

    public function test_hidden_standard_fields_are_absent_from_both_exports(): void
    {
        $this->project->forceFill(['hidden_standard_fields' => ['pillar', 'topic', 'hashtags', 'notes']])->save();
        ContentItem::factory()->for($this->project, 'project')->create([
            'title' => 'Con datos ocultos', 'hashtags' => '#oculto', 'notes' => 'Nota oculta',
        ]);

        $headings = app(ContentGridExportService::class)->headings($this->project->fresh());
        foreach (['Pilar de contenido', 'Tema', 'Hashtags', 'Notas'] as $hidden) {
            $this->assertNotContains($hidden, $headings);
        }
        foreach (['Fecha', 'Estado', 'Atrasado', 'Objetivos', 'Formato', 'Red social o canal', 'Gancho o título', 'Descripción o copy'] as $shown) {
            $this->assertContains($shown, $headings);
        }

        $sheet = $this->sheet($this->get(route('content.projects.contents.export.excel', $this->project)));
        $headerRow = $this->headerRow($sheet);
        $excelHeadings = $sheet->rangeToArray('A'.$headerRow.':'.$sheet->getHighestColumn().$headerRow)[0];
        $this->assertSame($headings, $excelHeadings);
        $dataRow = $sheet->rangeToArray('A'.($headerRow + 1).':'.$sheet->getHighestColumn().($headerRow + 1))[0];
        $this->assertCount(count($headings), $dataRow);
        $this->assertNotContains('#oculto', $dataRow);
        $this->assertNotContains('Nota oculta', $dataRow);

        $captured = $this->capturePdfData();
        $this->get(route('content.projects.contents.export.pdf', $this->project))->assertOk();
        $this->assertSame($headings, $captured['headings']);
        $this->assertCount(count($headings), $captured['rows'][0]);
    }

    // Helpers ----------------------------------------------------------------

    /**
     * The data the PDF view is rendered with, filled once the export renders it.
     *
     * @return \ArrayObject<string, mixed>
     */
    private function capturePdfData(): \ArrayObject
    {
        $captured = new \ArrayObject;
        View::composer('content.exports.pdf', function ($view) use ($captured): void {
            $captured->exchangeArray($view->getData());
        });

        return $captured;
    }

    private function sheet(TestResponse $response): Worksheet
    {
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'content_style_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        return $sheet;
    }

    private function headerRow(Worksheet $sheet): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if ($sheet->getCell('A'.$row)->getValue() === 'Fecha') {
                return $row;
            }
        }

        $this->fail('No heading row found');
    }

    private function titleBlock(Worksheet $sheet): string
    {
        $lines = [];
        for ($row = 1; $row < $this->headerRow($sheet); $row++) {
            $lines[] = (string) $sheet->getCell('A'.$row)->getValue();
        }

        return implode("\n", $lines);
    }

    private function column(Worksheet $sheet, string $heading): string
    {
        $headerRow = $this->headerRow($sheet);
        $headings = $sheet->rangeToArray('A'.$headerRow.':'.$sheet->getHighestColumn().$headerRow, null, true, true, true)[$headerRow];
        $column = array_search($heading, $headings, true);
        $this->assertNotFalse($column, 'Missing column '.$heading);

        return (string) $column;
    }

    /**
     * @return list<string>
     */
    private function columnValues(Worksheet $sheet, string $heading): array
    {
        $column = $this->column($sheet, $heading);
        $values = [];
        for ($row = $this->headerRow($sheet) + 1; $row <= $sheet->getHighestRow(); $row++) {
            $values[] = (string) $sheet->getCell($column.$row)->getValue();
        }

        return $values;
    }
}
