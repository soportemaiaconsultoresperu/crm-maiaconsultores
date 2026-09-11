<?php

namespace App\ValueObjects\Courses;

use App\Enums\Courses\AcademicDocumentType;

final readonly class CourseEligibilityResult
{
    /**
     * @param list<string> $missingConditions
     */
    public function __construct(
        public bool $eligible,
        public array $missingConditions,
        public ?AcademicDocumentType $documentType,
    ) {
    }
}
