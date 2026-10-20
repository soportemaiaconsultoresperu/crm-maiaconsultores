<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentItem;
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
 * Review fixes for the grid exports: S11 (headings neutralised), S13 (Peru
 * date format and overdue marker), S15 (row caps with a clear message) and S26
 * (inactive custom fields that still hold data are exported).
 */
class ContentExportReviewFixesTest extends TestCase
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

    // S11 ------------------------------------------------------------------

    public function test_a_custom_field_label_that_looks_like_a_formula_is_neutralised_in_the_headings(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)
            ->create(['key' => 'enlace', 'label' => '=HYPERLINK("http://evil.test","clic")']);

        $headings = app(ContentGridExportService::class)->headings($this->project);
        $this->assertContains('\'=HYPERLINK("http://evil.test","clic")', $headings);
        $this->assertNotContains('=HYPERLINK("http://evil.test","clic")', $headings);

        $rows = $this->excelRows($this->get(route('content.projects.contents.export.excel', $this->project)));
        $sheetHeadings = array_map(fn ($cell): string => (string) $cell, $rows[0]);

        $this->assertContains('\'=HYPERLINK("http://evil.test","clic")', $sheetHeadings);
        $this->assertNotContains('=HYPERLINK("http://evil.test","clic")', $sheetHeadings);
    }

    // S13 ------------------------------------------------------------------

    public function test_the_export_dates_are_rendered_on_the_peru_clock_as_d_m_y_and_h_i(): void
    {
        // 23:30 in Lima is already the next day in UTC.
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 23:30:00'),
            'title' => 'Domingo noche',
        ]);

        $rows = $this->excelRows($this->get(route('content.projects.contents.export.excel', $this->project)));
        $row = $this->rowByTitle($rows, 'Domingo noche');

        $this->assertSame('08/03/2026', (string) $row['Fecha']);
        $this->assertSame('domingo', (string) $row['Día']);
        $this->assertSame('23:30', (string) $row['Horario']);
    }

    public function test_the_export_marks_overdue_contents_as_atrasado_and_leaves_the_rest_blank(): void
    {
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2020-01-10 09:00:00'),
            'status' => ContentItemStatus::InProgress,
            'title' => 'Pasado sin publicar',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2020-01-11 09:00:00'),
            'status' => ContentItemStatus::Published,
            'title' => 'Pasado publicado',
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2099-01-11 09:00:00'),
            'status' => ContentItemStatus::Incomplete,
            'title' => 'Futuro',
        ]);

        $rows = $this->excelRows($this->get(route('content.projects.contents.export.excel', $this->project)));

        $this->assertContains('Atrasado', array_map(fn ($cell): string => (string) $cell, $rows[0]));
        $this->assertSame('Atrasado', (string) $this->rowByTitle($rows, 'Pasado sin publicar')['Atrasado']);
        $this->assertSame('', (string) $this->rowByTitle($rows, 'Pasado publicado')['Atrasado']);
        $this->assertSame('', (string) $this->rowByTitle($rows, 'Futuro')['Atrasado']);

        // The PDF is rendered from the same headings and rows.
        $export = app(ContentGridExportService::class);
        $html = view('content.exports.pdf', [
            'project' => $this->project,
            'headings' => $export->headings($this->project),
            'rows' => $export->rows($this->project, []),
            'filterSummary' => [],
            'generatedAt' => '01/01/2026 00:00',
        ])->render();

        $this->assertStringContainsString('<td>Atrasado</td>', $html);
    }

    // S15 ------------------------------------------------------------------

    public function test_a_pdf_export_over_the_row_cap_is_refused_with_a_message_asking_to_filter(): void
    {
        config(['content.export.pdf_max_rows' => 2]);
        ContentItem::factory()->for($this->project, 'project')->count(3)->create([
            'planned_at' => PeruTime::toUtc('2026-03-10 10:00:00'),
        ]);

        $response = $this->get(route('content.projects.contents.export.pdf', [
            'contentProject' => $this->project,
            'month' => '2026-03',
        ]));

        // Back to the same filtered grid, never a truncated document.
        $response->assertRedirect(route('content.projects.contents.index', [
            'contentProject' => $this->project,
            'month' => '2026-03',
        ]));
        $response->assertSessionHas('error');
        $message = (string) session('error');
        $this->assertStringContainsString('PDF', $message);
        $this->assertStringContainsString('2', $message);
        $this->assertStringContainsString('mes', $message);
    }

    public function test_a_pdf_export_at_the_row_cap_is_still_produced(): void
    {
        config(['content.export.pdf_max_rows' => 3]);
        ContentItem::factory()->for($this->project, 'project')->count(3)->create();

        $response = $this->get(route('content.projects.contents.export.pdf', $this->project));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_an_excel_export_over_the_row_cap_is_refused_and_a_filtered_one_is_produced(): void
    {
        config(['content.export.excel_max_rows' => 2]);
        ContentItem::factory()->for($this->project, 'project')->count(2)->create([
            'planned_at' => PeruTime::toUtc('2026-03-10 10:00:00'),
        ]);
        ContentItem::factory()->for($this->project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-04-10 10:00:00'),
        ]);

        $this->get(route('content.projects.contents.export.excel', $this->project))
            ->assertRedirect(route('content.projects.contents.index', $this->project))
            ->assertSessionHas('error');
        $this->assertStringContainsString('Excel', (string) session('error'));

        $this->get(route('content.projects.contents.export.excel', [
            'contentProject' => $this->project,
            'month' => '2026-03',
        ]))->assertOk();
    }

    public function test_the_default_caps_are_500_for_pdf_and_5000_for_excel(): void
    {
        $this->assertSame(500, config('content.export.pdf_max_rows'));
        $this->assertSame(5000, config('content.export.excel_max_rows'));
    }

    // S26 ------------------------------------------------------------------

    public function test_an_inactive_custom_field_that_still_holds_data_is_exported_with_an_inactive_suffix(): void
    {
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)
            ->create(['key' => 'antiguo', 'label' => 'Campo antiguo']);
        ContentFieldDefinition::factory()->for($this->project, 'project')
            ->ofType(ContentFieldType::Text)
            ->create(['key' => 'vacio', 'label' => 'Campo vacío', 'is_active' => false]);

        $item = ContentItem::factory()->for($this->project, 'project')->create(['title' => 'Con dato histórico']);
        app(ContentFieldValueService::class)->save($item, ['antiguo' => 'Valor histórico']);

        ContentFieldDefinition::query()->where('key', 'antiguo')->update(['is_active' => false]);

        $rows = $this->excelRows($this->get(route('content.projects.contents.export.excel', $this->project)));
        $headings = array_map(fn ($cell): string => (string) $cell, $rows[0]);

        $this->assertContains('Campo antiguo (inactivo)', $headings);
        $this->assertNotContains('Campo vacío', $headings);
        $this->assertNotContains('Campo vacío (inactivo)', $headings);
        $this->assertSame('Valor histórico', (string) $this->rowByTitle($rows, 'Con dato histórico')['Campo antiguo (inactivo)']);
    }

    /**
     * @return list<list<mixed>>
     */
    private function excelRows(TestResponse $response): array
    {
        $response->assertOk();

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

        return $sheets[0] ?? [];
    }

    /**
     * The data row whose "Gancho o título" matches, keyed by heading.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowByTitle(array $rows, string $title): array
    {
        $headings = array_map(fn ($cell): string => (string) $cell, $rows[0]);

        foreach (array_slice($rows, 1) as $row) {
            $keyed = array_combine($headings, $row);

            if ((string) $keyed['Gancho o título'] === $title) {
                return $keyed;
            }
        }

        $this->fail('No exported row titled '.$title);
    }
}
