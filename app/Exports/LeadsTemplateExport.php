<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Professional Excel template for the Prospectos (Leads) bulk-import workflow.
 *
 * The template mirrors exactly the columns, validation rules and dropdown
 * values accepted by LeadsImport + LeadStoreRequest. Two sheets are
 * produced:
 *
 *  - "Prospectos": headers in row 1 with brand styling, dropdown validation
 *    for enum-like columns, sensible column widths, and a frozen header
 *    row. One example row is included so the operator can see the expected
 *    shape before replacing it with real data.
 *
 *  - "Instrucciones": a how-to page that documents every column, the
 *    accepted values, and the rules enforced server-side on import.
 *
 * The output is generated entirely with PhpSpreadsheet so styling, widths
 * and validations are consistent across machines (no maatwebsite/excel
 * templating involved).
 */
class LeadsTemplateExport
{
    /** Column letters keyed by the human-readable Spanish header that the
     *  operator will see on row 1. Keys must stay in sync with
     *  LeadsImport::HEADER_TO_FIELD. */
    private const COLUMNS = [
        'Tipo de persona'                => 'A',
        'Nombre'                         => 'B',
        'Apellidos'                      => 'C',
        'Empresa'                        => 'D',
        'Razón social'                   => 'E',
        'Nombre comercial'               => 'F',
        'Cargo'                          => 'G',
        'Tipo de documento'              => 'H',
        'Número de documento'            => 'I',
        'Teléfono'                       => 'J',
        'WhatsApp'                       => 'K',
        'Correo electrónico'             => 'L',
        'Sitio web'                      => 'M',
        'Dirección'                      => 'N',
        'Código de distrito (ubigeo)'    => 'O',
        'Sector'                         => 'P',
        'Nivel de interés'               => 'Q',
        'Observaciones'                  => 'R',
    ];

    /**
     * Convert a 1-based column index to an Excel letter (1 -> "A", 27 -> "AA").
     * Note: PhpSpreadsheet's Coordinate::stringFromColumnIndex is itself
     * 1-based in modern versions (A = 1), so no offset is needed.
     */
    private static function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /** Column widths in Excel character units (matches PhpSpreadsheet defaults). */
    private const COLUMN_WIDTHS = [
        'A' => 18, 'B' => 26, 'C' => 22, 'D' => 30, 'E' => 34, 'F' => 28,
        'G' => 22, 'H' => 16, 'I' => 18, 'J' => 18, 'K' => 18, 'L' => 30,
        'M' => 28, 'N' => 38, 'O' => 16, 'P' => 22, 'Q' => 18, 'R' => 44,
    ];

    /** Sample row so the operator sees the expected format. */
    private const EXAMPLE_ROW = [
        'natural',
        'María',
        'Gonzales',
        '',
        '',
        '',
        'Gerente de compras',
        'dni',
        '45678901',
        '+51987654321',
        '+51987654321',
        'maria.gonzales@example.com',
        'https://example.com',
        'Av. La Marina 123, Lima',
        '150101',
        'Consultoría',
        'alto',
        'Contacto referido por el Sr. Pérez.',
    ];

    /**
     * Build the XlsxWriter ready for ->save() to a path or ->download().
     */
    public function build(): XlsxWriter
    {
        $spreadsheet = new Spreadsheet();

        $prospectos = $spreadsheet->getActiveSheet();
        $prospectos->setTitle('Prospectos');

        $this->buildProspectosSheet($prospectos);
        $this->buildInstruccionesSheet($spreadsheet->createSheet());

        return new XlsxWriter($spreadsheet);
    }

    /**
     * Render the data sheet: branded headers, one example row, dropdown
     * validations for enum-like columns, frozen header, column widths.
     */
    private function buildProspectosSheet(Worksheet $sheet): void
    {
        $headers = array_keys(self::COLUMNS);

        // Row 1: headers (human-readable Spanish labels).
        foreach ($headers as $i => $header) {
            $cell = $sheet->getCell(self::col($i + 1) . '1');
            $cell->setValue($header);
        }

        $headerRange = 'A1:' . self::COLUMNS[end($headers)] . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => Color::COLOR_WHITE],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF2563EB'], // CRM Maia primary blue
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF7C3AED']],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // Row 2: example row (light grey fill, italic) so the operator sees the format.
        $sheet->fromArray(self::EXAMPLE_ROW, null, 'A2');
        $exampleRange = 'A2:' . self::COLUMNS[end($headers)] . '2';
        $sheet->getStyle($exampleRange)->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['argb' => 'FF6C757D'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF8FAFC'],
            ],
        ]);

        // Dropdown validation per enum column. The keys are the same
        // human-readable headers used in COLUMNS above so a single constant
        // drives both the header text and the validation list.
        $dropdownColumns = [
            'Tipo de persona'   => '"natural,juridica"',
            'Tipo de documento' => '"dni,ruc,ce,pasaporte,otro"',
            'Nivel de interés'  => '"bajo,medio,alto"',
        ];
        foreach ($dropdownColumns as $header => $listLiteral) {
            $this->applyDropdown($sheet, $header, $listLiteral);
        }

        // Apply same dropdown to every data row (up to 5000) so operators
        // filling the file in Sheets/Excel still see the dropdown.
        $lastDataRow = 5001;
        foreach ($dropdownColumns as $header => $listLiteral) {
            $validation = $sheet->getCell(self::COLUMNS[$header] . '1')->getDataValidation();
            $validation->setShowDropDown(true); // hide the "select all" prompt
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setErrorTitle('Valor no permitido');
            $validation->setError('Elegí uno de los valores del dropdown.');
            $validation->setPromptTitle('Valores permitidos');
            $validation->setPrompt('Elegí uno de los valores de la lista.');
            for ($r = 3; $r <= $lastDataRow; $r++) {
                $sheet->getCell(self::COLUMNS[$header] . $r)->setDataValidation(clone $validation);
            }
        }

        // Column widths.
        foreach (self::COLUMN_WIDTHS as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // Freeze the header row so it stays visible while scrolling.
        $sheet->freezePane('A2');

        // Subtle banding for readability (every other row).
        $sheet->getStyle('A3:R' . $lastDataRow)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFFFFFFF');
        $sheet->getStyle('A3:R' . $lastDataRow)
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)
            ->getColor()
            ->setARGB('FFE9ECEF');
    }

    /**
     * Instructions sheet — a how-to reference with the same column ordering
     * as the Prospectos sheet so the operator can read across.
     */
    private function buildInstruccionesSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Instrucciones');

        // Title.
        $sheet->setCellValue('A1', 'Cómo usar esta plantilla de prospectos');
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => Color::COLOR_WHITE]],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0D6EFD'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(36);

        // Intro paragraph.
        $sheet->setCellValue('A2', 'Cada fila de la hoja "Prospectos" representa un prospecto. La primera fila contiene los encabezados obligatorios; la segunda fila es un ejemplo (gris claro) que conviene reemplazar por tus datos reales antes de importar. La fila 3 en adelante queda libre para que pegues tus prospectos.');
        $sheet->mergeCells('A2:C2');
        $sheet->getStyle('A2')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(2)->setRowHeight(60);

        // Column reference table header.
        $headerRow = 4;
        $headers = ['Columna', 'Descripción', 'Valores aceptados'];
        foreach ($headers as $i => $h) {
            $sheet->getCell(self::col($i + 1) . $headerRow)->setValue($h);
        }
        $sheet->getStyle("A{$headerRow}:C{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_WHITE]],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF495057'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        // Column docs.
        $docs = [
            ['person_type',    'Tipo de persona. Obligatorio.',                                          'natural | juridica'],
            ['first_name',     'Nombres. Obligatorio cuando person_type=natural.',                       'Texto libre'],
            ['last_name',      'Apellidos. Para jurídica puede quedar vacío.',                           'Texto libre'],
            ['company_name',   'Empresa. Recomendado y clave para prospectos jurídicos.',                'Texto libre'],
            ['legal_name',     'Razón social legal del prospecto jurídico.',                             'Texto libre'],
            ['trade_name',     'Nombre comercial o marca visible.',                                      'Texto libre'],
            ['position',       'Cargo del contacto dentro de la empresa.',                               'Texto libre'],
            ['doc_type',       'Tipo de documento. Recomendado.',                                        'dni | ruc | ce | pasaporte | otro'],
            ['doc_number',     'Número de documento. Al menos uno entre documento, email, phone o WhatsApp.', 'DNI 8 dígitos | RUC 11 dígitos'],
            ['phone',          'Teléfono principal.',                                                    'Texto libre'],
            ['whatsapp',       'WhatsApp comercial.',                                                    'Texto libre'],
            ['email',          'Correo electrónico.',                                                    'email válido'],
            ['website',        'Sitio web corporativo.',                                                 'URL válida con http:// o https://'],
            ['address',        'Dirección libre.',                                                       'Texto libre'],
            ['ubigeo_code',    'Código de distrito (ubigeo) de 6 dígitos.',                              '6 dígitos exactos'],
            ['sector',         'Sector, rubro o industria.',                                             'Texto libre'],
            ['interest_level', 'Nivel de interés declarado.',                                            'bajo | medio | alto'],
            ['observations',   'Observaciones libres.',                                                  'Texto libre'],
        ];

        $row = $headerRow + 1;
        foreach ($docs as $doc) {
            foreach ($doc as $i => $value) {
                $sheet->getCell(self::col($i + 1) . $row)->setValue($value);
            }
            $sheet->getStyle("A{$row}:C{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(28);
            // Light banding.
            if (($row - $headerRow) % 2 === 1) {
                $sheet->getStyle("A{$row}:C{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF8F9FA');
            }
            $row++;
        }

        // Notes section.
        $notesRow = $row + 1;
        $sheet->setCellValue("A{$notesRow}", 'Notas importantes');
        $sheet->mergeCells("A{$notesRow}:C{$notesRow}");
        $sheet->getStyle("A{$notesRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
        ]);
        $notes = [
            'El archivo se procesa en el servidor con validación fila por fila. Las filas inválidas se reportan con la razón exacta.',
            'Los duplicados (mismo documento, email o teléfono ya existentes) se omiten y se reportan; el sistema nunca actualiza prospectos existentes automáticamente (ADR-003).',
            'El import corre en una sola petición HTTP. Para archivos muy grandes, procesá por lotes de ~500 filas.',
            'Después de subir el archivo, el sistema te muestra un resumen (creados / omitidos / inválidos) con un detalle por fila.',
        ];
        foreach ($notes as $i => $note) {
            $r = $notesRow + 1 + $i;
            $sheet->setCellValue("A{$r}", '• ' . $note);
            $sheet->mergeCells("A{$r}:C{$r}");
            $sheet->getStyle("A{$r}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($r)->setRowHeight(24);
        }

        // Column widths.
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(60);
        $sheet->getColumnDimension('C')->setWidth(34);

        // Freeze the title row.
        $sheet->freezePane('A4');
    }

    /**
     * Attach a "list" data validation to the header cell of the given column
     * so Excel/Sheets show a dropdown on the data rows below.
     */
    private function applyDropdown(Worksheet $sheet, string $column, string $listLiteral): void
    {
        $cell = $sheet->getCell(self::COLUMNS[$column] . '1');
        $validation = $cell->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setFormula1($listLiteral);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true); // hide the "select all" prompt
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setErrorTitle('Valor no permitido');
        $validation->setError('Elegí uno de los valores del dropdown.');
        $validation->setPromptTitle('Valores permitidos');
        $validation->setPrompt('Elegí uno de los valores de la lista.');
    }
}
