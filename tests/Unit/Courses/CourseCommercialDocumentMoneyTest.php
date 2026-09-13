<?php

namespace Tests\Unit\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Services\Courses\CourseCommercialDocumentService;
use Tests\TestCase;

class CourseCommercialDocumentMoneyTest extends TestCase
{
    public function test_boleta_and_factura_add_configured_igv_to_activity_and_certificate_charges(): void
    {
        $money = app(CourseCommercialDocumentService::class)->calculateCharges(CommercialDocumentType::Boleta, '100.00', '20.00', '0.00');

        $this->assertSame('120.00', $money['subtotal_amount']);
        $this->assertSame('0.1800', $money['igv_rate']);
        $this->assertSame('21.60', $money['igv_amount']);
        $this->assertSame('141.60', $money['total_amount']);
    }

    public function test_recibo_has_no_igv(): void
    {
        $money = app(CourseCommercialDocumentService::class)->calculateCharges(CommercialDocumentType::Recibo, '100.00', '20.00', '5.00');

        $this->assertSame('0.0000', $money['igv_rate']);
        $this->assertSame('0.00', $money['igv_amount']);
        $this->assertSame('115.00', $money['total_amount']);
    }

    public function test_uses_integer_half_up_arithmetic_without_float_rounding(): void
    {
        config(['courses.igv_rate' => '0.175']);

        $money = app(CourseCommercialDocumentService::class)->calculate(CommercialDocumentType::Factura, '0.20');

        $this->assertSame('0.04', $money['igv_amount']);
        $this->assertSame('0.24', $money['total_amount']);
    }
}
