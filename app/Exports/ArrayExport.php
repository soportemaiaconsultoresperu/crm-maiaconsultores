<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Generic Excel exporter for arbitrary precomputed rows.
 *
 * Reports (Tanda A, RF-REP-004) build their data through the service layer
 * (DashboardService / ReportsService) and pass the already-grouped /
 * precomputed arrays here. This avoids spawning one exporter class per
 * report while keeping the headings explicit and Spanish by design.
 *
 * Usage:
 *   Excel::download(
 *       new ArrayExport(['Columna A', 'Columna B'], [['x', 'y'], ['a', 'b']]),
 *       'reporte.xlsx'
 *   );
 */
class ArrayExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }
}
