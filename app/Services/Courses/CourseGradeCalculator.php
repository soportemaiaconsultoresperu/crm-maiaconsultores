<?php

namespace App\Services\Courses;

use App\Enums\Courses\FinalResult;
use App\ValueObjects\Courses\GradeResult;
use InvalidArgumentException;

final class CourseGradeCalculator
{
    public static function calculate(iterable $grades): GradeResult
    {
        $sumHundredths = 0;
        $count = 0;

        foreach ($grades as $grade) {
            $sumHundredths += self::toHundredths($grade);
            $count++;
        }

        if ($count === 0) {
            throw new InvalidArgumentException('At least one grade is required.');
        }

        $exactAverage = self::formatAverage($sumHundredths, $count, 4);
        $displayAverage = self::formatAverage($sumHundredths, $count, 2);
        $roundedResult = self::roundHalfUpInteger($sumHundredths, $count);

        return new GradeResult(
            exactAverage: $exactAverage,
            displayAverage: $displayAverage,
            roundedResult: $roundedResult,
            finalResult: $roundedResult >= 13 ? FinalResult::Approved : FinalResult::Participation,
        );
    }

    private static function toHundredths(mixed $grade): int
    {
        if (! is_string($grade) && ! is_int($grade)) {
            throw new InvalidArgumentException('Grades must be decimal strings or integers.');
        }

        $normalized = trim((string) $grade);

        if (! preg_match('/^(?:20(?:\.00?)?|(?:[0-9]|1[0-9])(?:\.\d{1,2})?)$/', $normalized)) {
            throw new InvalidArgumentException("Invalid grade [{$normalized}].");
        }

        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    private static function formatAverage(int $sumHundredths, int $count, int $scale): string
    {
        $factor = 10 ** $scale;
        $quotient = intdiv($sumHundredths * $factor, $count * 100);
        $remainder = ($sumHundredths * $factor) % ($count * 100);

        if ($remainder * 2 >= $count * 100) {
            $quotient++;
        }

        return self::formatScaled($quotient, $scale);
    }

    private static function roundHalfUpInteger(int $sumHundredths, int $count): int
    {
        return intdiv(($sumHundredths * 2) + ($count * 100), $count * 200);
    }

    private static function formatScaled(int $value, int $scale): string
    {
        $whole = intdiv($value, 10 ** $scale);
        $decimal = $value % (10 ** $scale);

        return $whole . '.' . str_pad((string) $decimal, $scale, '0', STR_PAD_LEFT);
    }
}
