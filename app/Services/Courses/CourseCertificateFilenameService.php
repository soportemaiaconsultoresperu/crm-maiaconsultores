<?php

namespace App\Services\Courses;

use Carbon\CarbonInterface;

class CourseCertificateFilenameService
{
    public function build(
        string $participant,
        string $activity,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        string $company,
    ): string {
        return sprintf(
            'Certificado_%s_%s_%s_%s.pdf',
            $this->sanitize($participant),
            $this->sanitize($activity),
            $this->dateRange($startsOn, $endsOn),
            $this->sanitize($company),
        );
    }

    private function dateRange(CarbonInterface $startsOn, CarbonInterface $endsOn): string
    {
        if ($startsOn->isSameDay($endsOn)) {
            return $startsOn->format('d.m.y');
        }

        if ($startsOn->format('m.y') === $endsOn->format('m.y')) {
            return $startsOn->format('d').'-'.$endsOn->format('d.m.y');
        }

        return $startsOn->format('d.m.y').'-'.$endsOn->format('d.m.y');
    }

    private function sanitize(string $value): string
    {
        $safe = preg_replace('#[\\\\/:*?"<>|]+#', '-', $value) ?? '';
        $safe = preg_replace('/\s+/', ' ', $safe) ?? '';

        return trim($safe) ?: 'Sin dato';
    }
}
