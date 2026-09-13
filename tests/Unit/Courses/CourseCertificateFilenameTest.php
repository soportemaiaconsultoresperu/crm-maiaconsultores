<?php

namespace Tests\Unit\Courses;

use App\Services\Courses\CourseCertificateFilenameService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CourseCertificateFilenameTest extends TestCase
{
    public function test_builds_human_readable_reference_filename(): void
    {
        $filename = $this->service()->build('Alvaro Segundo Alama Silva', 'Curso Avanzado de Saneamiento Ambiental', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-04'), 'Maia Consultores');

        $this->assertSame('Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf', $filename);
    }

    public function test_sanitizes_forbidden_filesystem_characters_without_collapsing_spaces(): void
    {
        $filename = $this->service()->build('Rosa / Pérez: QA', 'Charla * Bioseguridad?', CarbonImmutable::parse('2026-08-10'), CarbonImmutable::parse('2026-08-10'), 'Maia <Academy>');

        $this->assertSame('Certificado_Rosa - Pérez- QA_Charla - Bioseguridad-_10.08.26_Maia -Academy-.pdf', $filename);
    }
    private function service(): CourseCertificateFilenameService
    {
        return new CourseCertificateFilenameService();
    }
}
