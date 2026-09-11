<?php

namespace Tests\Feature\Courses;

use App\Services\Courses\CourseCertificateTemplateService;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class CourseCertificateTemplateTest extends TestCase
{
    public function test_reference_template_renders_required_certificate_and_temario_pages(): void
    {
        $viewModel = (new CourseCertificateTemplateService())->makeViewModel([
            'title' => 'Certificado de aprobación',
            'participant' => 'Alvaro Segundo Alama Silva',
            'activity' => 'Curso Avanzado de Saneamiento Ambiental',
            'modality' => 'Híbrida',
            'date_range' => '01-04.07.26',
            'academic_hours' => '24',
            'issue_location_date' => 'Lima, 05 de julio de 2026',
            'certificate_code' => 'CERT-2026-0001',
            'qr_svg' => '<svg role="img"><text>QR</text></svg>',
            'signatures' => [
                ['name' => 'Dra. Andrea Maia', 'role' => 'Dirección Académica'],
                ['name' => 'Ing. Luis Rojas', 'role' => 'Coordinación'],
            ],
            'syllabus' => [
                ['class' => 'Clase 1', 'topic' => 'Marco normativo', 'speaker' => 'Dra. Andrea Maia', 'date' => '01.07.26'],
                ['class' => 'Clase 2', 'topic' => 'Fiscalización sanitaria', 'speaker' => 'Ing. Luis Rojas', 'date' => '02.07.26'],
            ],
        ]);

        $html = View::make('course-talks.certificates.reference', ['certificate' => $viewModel])->render();

        $this->assertStringContainsString('Certificado de aprobación', $html);
        $this->assertStringContainsString('Alvaro Segundo Alama Silva', $html);
        $this->assertStringContainsString('Curso Avanzado de Saneamiento Ambiental', $html);
        $this->assertStringContainsString('Híbrida', $html);
        $this->assertStringContainsString('01-04.07.26', $html);
        $this->assertStringContainsString('24 horas académicas', $html);
        $this->assertStringContainsString('Lima, 05 de julio de 2026', $html);
        $this->assertStringContainsString('CERT-2026-0001', $html);
        $this->assertStringContainsString('Dra. Andrea Maia', $html);
        $this->assertStringContainsString('Ing. Luis Rojas', $html);
        $this->assertStringContainsString('Temario', $html);
        $this->assertStringContainsString('Marco normativo', $html);
        $this->assertStringContainsString('Fiscalización sanitaria', $html);
        $this->assertStringContainsString('page-break-after: always', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
    }

    public function test_admin_settings_customize_text_and_signatures_without_removing_required_data(): void
    {
        $viewModel = (new CourseCertificateTemplateService())->makeViewModel([
            'participant' => 'Rosa Pérez',
            'activity' => 'Charla de Bioseguridad',
            'modality' => 'Virtual',
            'date_range' => '10.08.26',
            'academic_hours' => '2.5',
            'issue_location_date' => 'Arequipa, 11 de agosto de 2026',
            'certificate_code' => 'CHARLA-77',
            'qr_svg' => '<svg><text>QR2</text></svg>',
            'syllabus' => [['class' => 'Única', 'topic' => 'Buenas prácticas', 'speaker' => 'Equipo Maia', 'date' => '10.08.26']],
        ], [
            'title' => 'Constancia oficial',
            'intro_text' => 'Otorga la presente constancia a',
            'company' => 'Maia Academy',
            'signatures' => [
                ['name' => 'Firma Uno', 'role' => 'Gerencia'],
                ['name' => 'Firma Dos', 'role' => 'Calidad'],
            ],
        ]);

        $html = View::make('course-talks.certificates.reference', ['certificate' => $viewModel])->render();

        $this->assertStringContainsString('Constancia oficial', $html);
        $this->assertStringContainsString('Otorga la presente constancia a', $html);
        $this->assertStringContainsString('Rosa Pérez', $html);
        $this->assertStringContainsString('Charla de Bioseguridad', $html);
        $this->assertStringContainsString('Virtual', $html);
        $this->assertStringContainsString('2.5 horas académicas', $html);
        $this->assertStringContainsString('Firma Uno', $html);
        $this->assertStringContainsString('Firma Dos', $html);
        $this->assertStringContainsString('Maia Academy', $html);
        $this->assertStringContainsString('Buenas prácticas', $html);
    }
}
