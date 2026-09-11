<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Http\Requests\CourseTalks\StoreCommercialDocumentRequest;
use App\Http\Requests\CourseTalks\UploadCommercialDocumentRequest;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CourseCommercialDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseCommercialDocumentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('course-talks.commercial-documents.manage');
        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo('course-talks.commercial-documents.manage');
    }

    public function test_it_registers_an_external_factura_for_one_enrollment_with_pending_upload_and_audit_values(): void
    {
        $enrollment = CourseEnrollment::factory()->create([
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
            'series' => 'F001',
            'number' => '00001234',
            'issue_date' => '2026-08-26',
            'observations' => 'Emitido externamente.',
        ], $this->actor);

        $this->assertSame($enrollment->id, $commercial->course_enrollment_id);
        $this->assertNull($commercial->course_enrollment_group_id);
        $this->assertSame('120.00', $commercial->subtotal_amount);
        $this->assertSame('0.1800', $commercial->igv_rate);
        $this->assertSame('21.60', $commercial->igv_amount);
        $this->assertSame('141.60', $commercial->total_amount);
        $this->assertSame('PEN', $commercial->currency);
        $this->assertSame('pending_file', $commercial->status);
        $this->assertNull($commercial->document_id);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_it_uploads_private_attachments_and_preserves_the_replaced_document_for_audit(): void
    {
        Storage::fake('docs');
        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'payer_name' => 'Pagador',
        ], $this->actor);
        $service = app(CourseCommercialDocumentService::class);

        $first = $service->upload($commercial, UploadedFile::fake()->create('boleta-1.pdf', 4, 'application/pdf'), $this->actor);
        $second = $service->upload($commercial, UploadedFile::fake()->create('boleta-2.pdf', 4, 'application/pdf'), $this->actor);

        $commercial->refresh();
        $this->assertSame($second->id, $commercial->document_id);
        $this->assertSame('registered', $commercial->status);
        $this->assertStringStartsWith('course-commercial-documents/'.$commercial->id.'/', $second->path);
        $this->assertTrue(Storage::disk('docs')->exists($first->path));
        $this->assertTrue(Storage::disk('docs')->exists($second->path));
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseHas('documents', ['id' => $first->id, 'docable_id' => $commercial->id]);
    }

    public function test_it_uses_zero_igv_for_recibo_and_accepts_one_group_target(): void
    {
        $group = CourseEnrollmentGroup::factory()->create();

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Recibo, [
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Grupo pagador',
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '5.00',
        ], $this->actor);

        $this->assertSame($group->id, $commercial->course_enrollment_group_id);
        $this->assertSame('115.00', $commercial->subtotal_amount);
        $this->assertSame('0.0000', $commercial->igv_rate);
        $this->assertSame('0.00', $commercial->igv_amount);
        $this->assertSame('115.00', $commercial->total_amount);
    }

    public function test_it_rejects_missing_or_multiple_targets_negative_amounts_registered_without_a_file_and_unauthorized_actors(): void
    {
        $enrollment = CourseEnrollment::factory()->create();
        $group = CourseEnrollmentGroup::factory()->create();
        $service = app(CourseCommercialDocumentService::class);

        foreach ([
            ['payer_name' => 'Sin destino'],
            ['course_enrollment_id' => $enrollment->id, 'course_enrollment_group_id' => $group->id, 'payer_name' => 'Dos destinos'],
            ['course_enrollment_id' => $enrollment->id, 'payer_name' => 'Monto negativo', 'activity_price_amount' => '-1.00'],
            ['course_enrollment_id' => $enrollment->id, 'payer_name' => 'Sin archivo', 'status' => 'registered'],
        ] as $attributes) {
            try {
                $service->register(CommercialDocumentType::Boleta, $attributes, $this->actor);
                $this->fail('Expected the registration to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('course_commercial_documents', 0);
            }
        }

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'No autorizado',
        ], User::factory()->create());
    }

    public function test_upload_keeps_document_service_file_validation_as_a_required_second_line_of_defence(): void
    {
        Storage::fake('docs');
        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'payer_name' => 'Pagador',
        ], $this->actor);

        $this->expectException(InvalidArgumentException::class);
        app(CourseCommercialDocumentService::class)->upload(
            $commercial,
            UploadedFile::fake()->create('malicioso.exe', 1, 'application/x-msdownload'),
            $this->actor,
        );
    }

    public function test_registration_and_upload_requests_reject_invalid_targets_and_missing_required_file(): void
    {
        $registration = Validator::make([
            'type' => 'factura',
            'payer_name' => 'Pagador',
            'course_enrollment_id' => null,
            'course_enrollment_group_id' => null,
        ], (new StoreCommercialDocumentRequest())->rules());
        $upload = Validator::make(['status' => 'registered'], (new UploadCommercialDocumentRequest())->rules());
        $targetConflict = new StoreCommercialDocumentRequest();
        $targetConflict->replace([
            'type' => 'factura',
            'payer_name' => 'Pagador',
            'course_enrollment_id' => 1,
            'course_enrollment_group_id' => 2,
        ]);
        $conflictValidator = Validator::make($targetConflict->all(), $targetConflict->rules());
        $targetConflict->withValidator($conflictValidator);

        $this->assertTrue($registration->fails());
        $this->assertTrue($upload->fails());
        $this->assertArrayHasKey('file', $upload->errors()->toArray());
        $this->assertTrue($conflictValidator->fails());
        $this->assertArrayHasKey('course_enrollment_id', $conflictValidator->errors()->toArray());
    }
}
