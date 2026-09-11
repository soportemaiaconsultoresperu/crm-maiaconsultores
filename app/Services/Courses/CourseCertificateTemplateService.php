<?php

namespace App\Services\Courses;

use App\ViewModels\Courses\CourseCertificateViewModel;

class CourseCertificateTemplateService
{
    public function makeViewModel(array $businessData, array $settings = []): CourseCertificateViewModel
    {
        $signatures = $businessData['signatures'] ?? $settings['signatures'] ?? $this->defaultSignatures();

        return new CourseCertificateViewModel(
            title: (string) ($settings['title'] ?? $businessData['title'] ?? 'Certificado'),
            introText: (string) ($settings['intro_text'] ?? 'Otorga el presente certificado a'),
            participant: (string) $businessData['participant'],
            activity: (string) $businessData['activity'],
            modality: (string) $businessData['modality'],
            dateRange: (string) $businessData['date_range'],
            academicHours: (string) $businessData['academic_hours'],
            issueLocationDate: (string) $businessData['issue_location_date'],
            certificateCode: (string) $businessData['certificate_code'],
            qrSvg: (string) $businessData['qr_svg'],
            signatures: array_slice(array_values($signatures), 0, 2),
            syllabus: array_values($businessData['syllabus'] ?? []),
            company: (string) ($settings['company'] ?? 'Maia Consultores'),
        );
    }

    private function defaultSignatures(): array
    {
        return [
            ['name' => 'Dirección Académica', 'role' => 'Maia Consultores'],
            ['name' => 'Coordinación Académica', 'role' => 'Maia Consultores'],
        ];
    }
}
