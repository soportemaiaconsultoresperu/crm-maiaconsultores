<?php

namespace App\Exports\Content;

use App\Models\Content\ContentProject;
use App\Support\Content\ProjectColor;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Content grid Excel export (S3, S1.4 of content-color-fields-export).
 *
 * The headings and rows arrive from ContentGridExportService already
 * neutralised by ContentCellSanitizer; this class only lays them out. Above the
 * table sits a title block (project, applied filters and range, generation time
 * on the Peru clock), whose user text is neutralised here too. The header row
 * is bold on the project colour with the readable text colour, the pane is
 * frozen below it, the table carries an auto filter, long text columns wrap
 * with fixed widths and every "Atrasado" marker is highlighted.
 */
class ContentGridExcelExport implements FromArray, WithCustomStartCell, WithEvents, WithHeadings, WithStyles, WithTitle
{
    /** Title block lines plus one blank spacer row sit above the header. */
    private const HEADER_ROW = 5;

    private const OVERDUE_FILL = 'F8D7DA';

    private const OVERDUE_TEXT = '842029';

    /**
     * Column widths by heading; custom-field columns use the default width.
     *
     * @var array<string, int>
     */
    private const WIDTHS = [
        'Fecha' => 12,
        'Día' => 11,
        'Horario' => 9,
        'Pilar de contenido' => 22,
        'Tema' => 22,
        'Estado' => 26,
        'Atrasado' => 11,
        'Objetivos' => 24,
        'Formato' => 18,
        'Red social o canal' => 20,
        'Gancho o título' => 36,
        'Descripción o copy' => 60,
        'Hashtags' => 26,
        'Notas' => 45,
    ];

    private const DEFAULT_WIDTH = 22;

    /** Headings whose cells wrap instead of overflowing. */
    private const WRAPPED = ['Objetivos', 'Gancho o título', 'Descripción o copy', 'Hashtags', 'Notas'];

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $filterSummary
     */
    public function __construct(
        private readonly ContentProject $project,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $filterSummary,
        private readonly string $generatedAt,
    ) {
    }

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

    public function startCell(): string
    {
        return 'A'.self::HEADER_ROW;
    }

    public function title(): string
    {
        return 'Cronograma';
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $color = ProjectColor::resolve($this->project->color);
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));

        // Only the table's header cells, not the whole spreadsheet row.
        return [
            'A'.self::HEADER_ROW.':'.$lastColumn.self::HEADER_ROW => [
                'font' => ['bold' => true, 'color' => ['rgb' => $this->rgb(ProjectColor::textColor($color))]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->rgb($color)]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ],
        ];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->decorate($event->sheet->getDelegate());
            },
        ];
    }

    private function decorate(Worksheet $sheet): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));
        $lastRow = self::HEADER_ROW + count($this->rows);
        $color = ProjectColor::resolve($this->project->color);

        $this->writeTitleBlock($sheet, $lastColumn, $color);

        $sheet->freezePane('A'.(self::HEADER_ROW + 1));
        $sheet->setAutoFilter('A'.self::HEADER_ROW.':'.$lastColumn.$lastRow);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(22);

        $table = $sheet->getStyle('A'.self::HEADER_ROW.':'.$lastColumn.$lastRow);
        $table->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D0D7E2');
        $table->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach ($this->headings as $index => $heading) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth(self::WIDTHS[$heading] ?? self::DEFAULT_WIDTH);

            if ($lastRow > self::HEADER_ROW && in_array($heading, self::WRAPPED, true)) {
                $sheet->getStyle($column.(self::HEADER_ROW + 1).':'.$column.$lastRow)->getAlignment()->setWrapText(true);
            }
        }

        $this->highlightOverdue($sheet, $lastRow);
    }

    /**
     * Project name, applied filters and generation time, each on one merged
     * row spanning the table width.
     */
    private function writeTitleBlock(Worksheet $sheet, string $lastColumn, string $color): void
    {
        $lines = [
            'Cronograma de contenidos — '.$this->project->name,
            $this->filterSummary === []
                ? 'Sin filtros aplicados.'
                : 'Filtros aplicados: '.implode(' · ', $this->filterSummary),
            'Generado el '.$this->generatedAt.' (hora de Perú)',
        ];

        foreach ($lines as $offset => $line) {
            $row = $offset + 1;
            $sheet->setCellValue('A'.$row, ContentCellSanitizer::sanitize($line));
            $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($this->rgb($color));
        $sheet->getStyle('A2:A3')->getFont()->setSize(10)->getColor()->setRGB('475569');
    }

    private function highlightOverdue(Worksheet $sheet, int $lastRow): void
    {
        $index = array_search('Atrasado', $this->headings, true);

        if ($index === false) {
            return;
        }

        $column = Coordinate::stringFromColumnIndex($index + 1);

        for ($row = self::HEADER_ROW + 1; $row <= $lastRow; $row++) {
            if ($sheet->getCell($column.$row)->getValue() !== 'Atrasado') {
                continue;
            }

            $style = $sheet->getStyle($column.$row);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::OVERDUE_FILL);
            $style->getFont()->setBold(true)->getColor()->setRGB(self::OVERDUE_TEXT);
        }
    }

    private function rgb(string $hex): string
    {
        return strtoupper(ltrim($hex, '#'));
    }
}
