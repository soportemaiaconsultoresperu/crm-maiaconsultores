<?php

namespace Tests\Feature;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Lead;
use App\Models\User;
use App\Services\DocumentService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\FileFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * B09 / RF-DOC-001..005 — DocumentService unit tests.
 *
 * Covers the upload / download / delete lifecycle plus the validation
 * surface (extension, MIME, size) and the activity log entries.
 * The class is exercised through the public API only; private helpers
 * (assertExtension, assertMimeMatchesExtension, assertSize) are
 * verified via the upload() outcomes, matching the project's testing
 * style for services.
 */
class DocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = app(DocumentService::class);
        // Vendedor is the broadest non-admin role: it has documents.upload
        // + documents.download + documents.delete + view.own so all
        // service paths can be exercised without crossing teams.
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('vendedor');
    }

    public function test_upload_validates_extension_mime_and_size(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        // .exe is rejected at the extension whitelist.
        $badExt = UploadedFile::fake()->create('notavirus.exe', 1, 'application/x-msdownload');
        try {
            $this->service->upload($lead, $badExt, $this->actor);
            $this->fail('Expected extension whitelist rejection.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Extensión no permitida', $e->getMessage());
        }
        $this->assertSame(0, Document::count(), 'No DB row when extension check fails');

        // .pdf name with mismatched MIME is rejected.
        $badMime = UploadedFile::fake()->createWithContent(
            'informe.pdf',
            '%PDF-1.4 not really'
        );
        // fake()->createWithContent returns mime application/pdf for .pdf
        // extension but its real content is plain text. Re-mime it by
        // constructing a fake with explicit mime mismatch.
        $badMime2 = $this->fakePdfWithWrongMime();

        try {
            $this->service->upload($lead, $badMime2, $this->actor);
            $this->fail('Expected MIME/extension cross-check rejection.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no coincide con la extensión', $e->getMessage());
        }

        // Oversize is rejected.
        $big = UploadedFile::fake()->create('gigante.pdf', 11 * 1024, 'application/pdf');
        try {
            $this->service->upload($lead, $big, $this->actor);
            $this->fail('Expected size cap rejection.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tamaño máximo', $e->getMessage());
        }

        // A valid upload succeeds and persists nothing extra beyond the
        // expected row.
        $good = UploadedFile::fake()->create('ok.pdf', 2, 'application/pdf');
        $document = $this->service->upload($lead, $good, $this->actor);

        $this->assertSame(1, Document::count());
        $this->assertSame('pdf', $document->extension);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame($this->actor->id, $document->uploaded_by);
    }

    public function test_upload_stores_file_on_private_disk_and_records_metadata(): void
    {
        Storage::fake('docs');

        $customer = Customer::factory()->forOwner($this->actor)->create();
        $file = UploadedFile::fake()->create('contrato.pdf', 4, 'application/pdf');

        $document = $this->service->upload($customer, $file, $this->actor);

        $this->assertSame('docs', $document->disk);
        $this->assertStringStartsWith('customers/'.$customer->id.'/', $document->path);
        $this->assertTrue(Storage::disk('docs')->exists($document->path));

        // metadata is filled correctly.
        $this->assertSame('contrato.pdf', $document->name);
        $this->assertGreaterThan(0, $document->size_bytes);
        $this->assertSame($this->actor->id, $document->uploaded_by);
        $this->assertNotNull($document->uploaded_at);
        $this->assertSame(Customer::class, $document->docable_type);
        $this->assertSame($customer->id, (int) $document->docable_id);
    }

    public function test_download_returns_streamed_response_with_correct_filename(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->actor)->create();
        $file = UploadedFile::fake()->create('presupuesto.pdf', 2, 'application/pdf');
        $document = $this->service->upload($lead, $file, $this->actor);

        $response = $this->service->download($document, $this->actor);

        $this->assertInstanceOf(StreamedResponse::class, $response);

        // Content-Disposition header carries the original filename, not the
        // internal storage filename.
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('presupuesto.pdf', $disposition);
    }

    public function test_delete_removes_physical_file_and_db_row(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->actor)->create();
        $document = $this->service->upload(
            $lead,
            UploadedFile::fake()->create('borrar.pdf', 1, 'application/pdf'),
            $this->actor,
        );

        $path = $document->path;
        $this->assertTrue(Storage::disk('docs')->exists($path));

        $this->service->delete($document, $this->actor);

        $this->assertSame(0, Document::count());
        $this->assertFalse(Storage::disk('docs')->exists($path));
    }

    /**
     * Defect A — a certificate PDF that a course document still references must
     * survive a deletion attempt. The two course tables declare
     * `document_id` with an implicit RESTRICT, so the old file-first order
     * destroyed the private file and then died on the foreign key: an
     * irrecoverable PII loss, a row that can never be deleted, and no audit row.
     */
    public function test_a_document_referenced_by_a_course_certificate_loses_nothing(): void
    {
        Storage::fake('docs');

        [$academic, $document] = $this->academicDocumentWithFile();
        $path = (string) $document->path;

        $this->assertTrue(Storage::disk('docs')->exists($path));

        $rejection = null;

        try {
            $this->service->delete($document, $this->actor);
        } catch (\Throwable $exception) {
            $rejection = $exception;
        }

        // The damage assertions come first on purpose: against the pre-fix code
        // the file is already gone at this point and THIS is the failing line.
        $this->assertTrue(
            Storage::disk('docs')->exists($path),
            'El archivo privado del certificado NO debe destruirse mientras un documento de curso lo referencia.',
        );
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'path' => $path]);
        $this->assertSame($document->id, (int) $academic->fresh()->document_id);
        $this->assertSame(
            0,
            Activity::query()->where('event', 'document-deleted')->count(),
            'Una eliminación rechazada no debe dejar una entrada de auditoría de borrado.',
        );
        $this->assertInstanceOf(
            ConflictHttpException::class,
            $rejection,
            'La eliminación de un documento referenciado debe rechazarse con un mensaje claro, no fallar a medias.',
        );
    }

    /**
     * A comprobante keeps the same reference, so the same protection applies to
     * the commercial side of the module.
     */
    public function test_a_document_referenced_by_a_commercial_document_loses_nothing(): void
    {
        Storage::fake('docs');

        [$commercial, $document] = $this->commercialDocumentWithFile();
        $path = (string) $document->path;

        $rejection = null;

        try {
            $this->service->delete($document, $this->actor);
        } catch (\Throwable $exception) {
            $rejection = $exception;
        }

        $this->assertTrue(
            Storage::disk('docs')->exists($path),
            'El archivo privado del comprobante NO debe destruirse mientras el comprobante lo referencia.',
        );
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'path' => $path]);
        $this->assertSame($document->id, (int) $commercial->fresh()->document_id);
        $this->assertInstanceOf(ConflictHttpException::class, $rejection);
    }

    /**
     * TRIANGULATE — the reference is the database row, not the model state: a
     * soft-deleted course document still blocks the foreign key, so it must
     * still block the file destruction.
     */
    public function test_a_soft_deleted_course_certificate_still_protects_its_file(): void
    {
        Storage::fake('docs');

        [$academic, $document] = $this->academicDocumentWithFile();
        $academic->delete();

        $path = (string) $document->path;

        try {
            $this->service->delete($document, $this->actor);
            $this->fail('Un certificado con soft delete sigue bloqueando la clave foránea y debe rechazar el borrado.');
        } catch (ConflictHttpException) {
            // expected
        }

        $this->assertTrue(Storage::disk('docs')->exists($path));
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertSoftDeleted('course_academic_documents', ['id' => $academic->id]);
    }

    /**
     * TRIANGULATE — the happy path is untouched: replacing a comprobante's
     * attachment leaves the previous row unreferenced, and the operator can
     * still remove it together with its file.
     */
    public function test_a_superseded_course_attachment_is_still_deletable_with_its_file(): void
    {
        Storage::fake('docs');

        [$commercial, $first] = $this->commercialDocumentWithFile();

        $second = $this->privateDocumentFor(
            $commercial,
            "course-commercial-documents/{$commercial->id}/reemplazo.pdf",
        );
        $commercial->forceFill(['document_id' => $second->id])->save();

        $this->service->delete($first, $this->actor);

        $this->assertFalse(Storage::disk('docs')->exists((string) $first->path));
        $this->assertDatabaseMissing('documents', ['id' => $first->id]);

        // The attachment the comprobante points at NOW is still protected.
        try {
            $this->service->delete($second, $this->actor);
            $this->fail('El adjunto vigente del comprobante no debe poder eliminarse.');
        } catch (ConflictHttpException) {
            // expected
        }

        $this->assertTrue(Storage::disk('docs')->exists((string) $second->path));
    }

    public function test_upload_logs_document_uploaded_activity(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $document = $this->service->upload(
            $lead,
            UploadedFile::fake()->create('auditable.pdf', 1, 'application/pdf'),
            $this->actor,
        );

        $log = Activity::query()
            ->where('event', 'document-uploaded')
            ->where('subject_type', Document::class)
            ->where('subject_id', $document->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'document-uploaded activity must be logged.');
        $this->assertSame($this->actor->id, (int) $log->causer_id);
        $props = $log->properties;
        $this->assertSame(Lead::class, $props['subject_type']);
        $this->assertSame($lead->id, $props['subject_id']);
        $this->assertSame('application/pdf', $props['mime_type']);
    }

    /**
     * A current certificate plus the private `documents` row it references —
     * the exact state `CourseDocumentGenerationService` persists once the PDF
     * write commits.
     *
     * @return array{0: CourseAcademicDocument, 1: Document}
     */
    private function academicDocumentWithFile(): array
    {
        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-RED-'.str()->upper(str()->random(8)),
            'issue_date' => now()->toDateString(),
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        $document = $this->privateDocumentFor(
            $academic,
            "course-academic-documents/{$academic->course_enrollment_id}/{$academic->code}.pdf",
        );

        $academic->forceFill(['document_id' => $document->id])->save();

        return [$academic->fresh(), $document];
    }

    /**
     * A registered comprobante plus the private `documents` row it references.
     *
     * @return array{0: CourseCommercialDocument, 1: Document}
     */
    private function commercialDocumentWithFile(): array
    {
        $commercial = CourseCommercialDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B001',
            'number' => (string) random_int(100000, 999999),
            'issue_date' => now()->toDateString(),
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Pagador de prueba',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        $document = $this->privateDocumentFor($commercial, "course-commercial-documents/{$commercial->id}/{$commercial->number}.pdf");

        $commercial->forceFill(['document_id' => $document->id])->save();

        return [$commercial->fresh(), $document];
    }

    /**
     * Persist a real file on the private `docs` disk plus the `documents` row
     * an upload would have written for the given subject.
     */
    private function privateDocumentFor(\Illuminate\Database\Eloquent\Model $subject, string $path): Document
    {
        Storage::disk('docs')->put($path, '%PDF documento de curso de prueba');

        return Document::query()->create([
            'docable_type' => $subject->getMorphClass(),
            'docable_id' => (int) $subject->getKey(),
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 32,
            'uploaded_by' => (int) $this->actor->id,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Build an UploadedFile whose name ends in .pdf but whose reported MIME
     * is something else. The default `UploadedFile::fake()->create()` infers
     * MIME from the extension, so we use the lower-level factory.
     */
    private function fakePdfWithWrongMime(): UploadedFile
    {
        // Create with explicit MIME that does not match the .pdf extension.
        return UploadedFile::fake()->create('informe.pdf', 1, 'image/png');
    }
}