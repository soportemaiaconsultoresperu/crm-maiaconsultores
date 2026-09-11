<?php

namespace Tests\Unit\Courses;

use App\Enums\Courses\FinalResult;
use App\Services\Courses\CourseGradeCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CourseGradeCalculatorTest extends TestCase
{
    public function test_1249_average_is_participation(): void
    {
        $result = CourseGradeCalculator::calculate(['12.49']);

        $this->assertSame('12.4900', $result->exactAverage);
        $this->assertSame('12.49', $result->displayAverage);
        $this->assertSame(12, $result->roundedResult);
        $this->assertSame(FinalResult::Participation, $result->finalResult);
    }

    public function test_half_up_boundary_approves_1250_and_1260(): void
    {
        $atHalf = CourseGradeCalculator::calculate(['12.50']);
        $aboveHalf = CourseGradeCalculator::calculate(['12.60']);

        $this->assertSame(13, $atHalf->roundedResult);
        $this->assertSame(FinalResult::Approved, $atHalf->finalResult);
        $this->assertSame(13, $aboveHalf->roundedResult);
        $this->assertSame(FinalResult::Approved, $aboveHalf->finalResult);
    }

    public function test_equal_weights_and_display_round_to_two_decimals_without_float_drift(): void
    {
        $result = CourseGradeCalculator::calculate(['12.33', '12.34', '12.34']);

        $this->assertSame('12.3367', $result->exactAverage);
        $this->assertSame('12.34', $result->displayAverage);
        $this->assertSame(12, $result->roundedResult);
        $this->assertSame(FinalResult::Participation, $result->finalResult);
    }

    public function test_rejects_empty_grade_sets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CourseGradeCalculator::calculate([]);
    }

    public function test_rejects_invalid_grade_values(): void
    {
        foreach (['', 'abc', '-0.01', '20.01', '12.345'] as $grade) {
            try {
                CourseGradeCalculator::calculate([$grade]);
                $this->fail("Grade {$grade} should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
