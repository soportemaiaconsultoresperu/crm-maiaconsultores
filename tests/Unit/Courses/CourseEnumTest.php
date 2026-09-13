<?php

namespace Tests\Unit\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\CourseModality;
use App\Enums\Courses\DeliveryStatus;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use PHPUnit\Framework\TestCase;

class CourseEnumTest extends TestCase
{
    public function test_course_enum_backing_values_match_domain_language(): void
    {
        $this->assertSame('course', CourseActivityType::Course->value);
        $this->assertSame('talk', CourseActivityType::Talk->value);
        $this->assertSame('hybrid', CourseModality::Hybrid->value);
        $this->assertSame('finished', CourseEditionState::Finished->value);
        $this->assertSame('confirmed', CourseEnrollmentState::Confirmed->value);
    }

    public function test_document_and_payment_enum_backing_values_are_persistable_strings(): void
    {
        $this->assertSame('paid', PaymentStatus::Paid->value);
        $this->assertSame('approved', FinalResult::Approved->value);
        $this->assertSame('talk_certificate', AcademicDocumentType::TalkCertificate->value);
        $this->assertSame('current', AcademicDocumentStatus::Current->value);
        $this->assertSame('pending', DeliveryStatus::Pending->value);
        $this->assertSame('factura', CommercialDocumentType::Factura->value);
    }
}
