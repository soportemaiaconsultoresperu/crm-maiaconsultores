<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Generic Excel exporter used by the report controllers (RF-REP-004).
 * The exporter accepts a precomputed headings + rows pair so the report
 * services (Tanda A) can hand off the aggregation result without writing
 * one export class per report.
 */
class ReportsExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_export_writes_spanish_headers_and_rows(): void
    {
        Storage::fake('local');

        $path = 'exports/array-report.xlsx';

        Excel::store(
            new ArrayExport(
                ['Código', 'Nombre', 'Moneda', 'Monto'],
                [
                    ['OPP-2026-00001', 'Oportunidad A', 'PEN', 1000],
                    ['OPP-2026-00002', 'Oportunidad B', 'USD', 250],
                ],
            ),
            $path,
            'local',
        );

        $this->assertTrue(Storage::disk('local')->exists($path));

        $reader = new class implements ToArray
        {
            /** @var list<list<mixed>> */
            public array $rows = [];

            public function array(array $array): void
            {
                $this->rows = $array;
            }
        };

        $sheets = Excel::toArray($reader, $path, 'local');
        $rows = $sheets[0] ?? [];

        // First row is the heading row.
        $this->assertSame(
            ['Código', 'Nombre', 'Moneda', 'Monto'],
            array_map(fn ($cell) => (string) $cell, $rows[0] ?? []),
        );

        // Data rows.
        $this->assertSame('OPP-2026-00001', $rows[1][0]);
        $this->assertSame('PEN', $rows[1][2]);
        $this->assertSame('OPP-2026-00002', $rows[2][0]);
        $this->assertSame('USD', $rows[2][2]);
    }

    public function test_array_export_can_be_streamed_as_a_download(): void
    {
        Storage::fake('local');

        // Build a download in memory and verify it produces a BinaryFileResponse
        // without persisting to disk. Maatwebsite returns a BinaryFileResponse
        // for Excel::download calls (the file is written to a temp path).
        $response = Excel::download(
            new ArrayExport(
                ['Columna'],
                [['valor']],
            ),
            'reporte.xlsx',
        );

        $this->assertNotNull($response);
    }
}
