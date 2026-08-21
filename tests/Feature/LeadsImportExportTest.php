<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadService;
use App\Imports\LeadsImport;
use App\Exports\LeadsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Tests\TestCase;

/**
 * Minimal named importer used to read raw rows back from a stored file.
 */
class RawSheetToArray implements ToArray
{
    /** @var list<list<string>> */
    public array $rows = [];

    public function array(array $array): void
    {
        $this->rows = $array;
    }
}

/**
 * RF-LEAD-007 / RF-LEAD-008: Excel import skips duplicates with a report
 * (ADR-003) and export respects the applied filters. Real file roundtrip
 * on the local storage disk — no fakes.
 */
class LeadsImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->actor = User::factory()->create();
        Storage::fake('local');
    }

    public function test_import_creates_valid_rows_and_reports_duplicates_and_invalids(): void
    {
        $existing = $this->createLead([
            'doc_type' => 'ruc',
            'doc_number' => '20123456789',
        ]);

        $path = 'imports/leads-test.xlsx';
        Excel::store(
            new class($this->rows()) implements FromArray, WithHeadings, ShouldAutoSize
            {
                public function __construct(private array $rows) {}

                public function array(): array
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return [
                        'person_type', 'first_name', 'last_name', 'doc_type',
                        'doc_number', 'phone', 'email',
                    ];
                }
            },
            $path,
            'local',
        );

        $import = new LeadsImport($this->actor);
        Excel::import($import, $path, 'local');

        $result = $import->result;

        // 4 data rows: 1 valid, 1 duplicate doc, 1 missing first_name, 1 bad RUC length.
        $this->assertSame(4, $result->total);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(2, $result->invalid);

        $skippedRow = collect($result->rows)->firstWhere('status', 'skipped');
        $this->assertSame($existing->code, $skippedRow['matched_lead_code']);
        $this->assertStringContainsString($existing->code, $skippedRow['reason']);

        $newLead = Lead::query()
            ->where('doc_number', '98765432')
            ->firstOrFail();
        $this->assertSame('María', $newLead->first_name);
        $this->assertSame($this->actor->id, $newLead->owner_id);
        $this->assertMatchesRegularExpression('/^LEAD-\d{4}-\d{5}$/', $newLead->code);

        // Existing lead untouched (no auto-updates, ADR-003).
        $this->assertSame('Juan', $existing->refresh()->first_name);
    }

    public function test_export_respects_filters_and_contains_lead_codes(): void
    {
        $seededSource = LeadSource::where('slug', 'web')->firstOrFail();

        $kept = $this->createLead(['first_name' => 'Ana', 'source_id' => $seededSource->id]);
        $other = LeadSource::firstOrCreate(
            ['slug' => 'referido'],
            ['name' => 'Referido', 'sort' => 2],
        );
        $excluded = $this->createLead(['first_name' => 'Luis', 'source_id' => $other->id]);

        $path = 'exports/leads-test.xlsx';
        Excel::store(new LeadsExport(['source_id' => $seededSource->id]), $path, 'local');

        $sheets = Excel::toArray(new RawSheetToArray(), $path, 'local');

            $flat = collect($sheets[0] ?? [])
                ->map(fn ($row) => collect($row)->map(fn ($cell) => (string) $cell)->implode('|'));

            $this->assertTrue($flat->contains(fn (string $line) => str_contains($line, $kept->code)));
            $this->assertFalse($flat->contains(fn (string $line) => str_contains($line, $excluded->code)));
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(): array
    {
        return [
            ['person_type' => 'natural', 'first_name' => 'María', 'last_name' => 'Torres', 'doc_type' => 'dni', 'doc_number' => '98765432', 'phone' => '51912345678', 'email' => 'maria@example.com'],
            ['person_type' => 'juridica', 'first_name' => 'Duplicado', 'last_name' => 'SA', 'doc_type' => 'ruc', 'doc_number' => '20123456789', 'phone' => '', 'email' => ''],
            ['person_type' => 'natural', 'first_name' => '', 'last_name' => '', 'doc_type' => '', 'doc_number' => '', 'phone' => '51987654321', 'email' => ''],
            ['person_type' => 'natural', 'first_name' => 'RucCorto', 'last_name' => '', 'doc_type' => 'ruc', 'doc_number' => '20123', 'phone' => '', 'email' => 'rucs@example.com'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLead(array $overrides = []): Lead
    {
        $source = LeadSource::firstOrCreate(
            ['slug' => 'web'],
            ['name' => 'Web', 'sort' => 1],
        );

        $data = array_merge([
            'person_type' => 'natural',
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'doc_type' => 'dni',
            'doc_number' => '12.345.678',
            'phone' => '+51 987 654 321',
            'email' => 'juan.perez@example.com',
            'source_id' => $source->id,
        ], $overrides);

        return app(LeadService::class)->create($data, $this->actor);
    }
}
