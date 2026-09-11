<?php

namespace App\ValueObjects\Courses;

use App\Enums\Courses\FinalResult;

final readonly class GradeResult
{
    public function __construct(
        public string $exactAverage,
        public string $displayAverage,
        public int $roundedResult,
        public FinalResult $finalResult,
    ) {
    }
}
