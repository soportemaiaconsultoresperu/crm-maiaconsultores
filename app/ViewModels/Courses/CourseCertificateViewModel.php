<?php

namespace App\ViewModels\Courses;

final class CourseCertificateViewModel
{
    public function __construct(
        public readonly string $title,
        public readonly string $introText,
        public readonly string $participant,
        public readonly string $activity,
        public readonly string $modality,
        public readonly string $dateRange,
        public readonly string $academicHours,
        public readonly string $issueLocationDate,
        public readonly string $certificateCode,
        public readonly string $qrSvg,
        public readonly array $signatures,
        public readonly array $syllabus,
        public readonly string $company,
    ) {}
}
