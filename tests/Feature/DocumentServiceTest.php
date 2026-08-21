<?php

namespace Tests\Feature;

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