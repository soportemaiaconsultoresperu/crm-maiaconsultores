<?php

namespace Tests\Feature;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * B09 / RF-DOC-001..005 — DocumentController HTTP layer.
 *
 * Covers the upload / download / delete endpoints across the morph
 * subjects (leads are used as the canonical example). Verifies the
 * auth/active middleware, the per-subject Gate::authorize gate on
 * upload, the documents.* permission gates, the 404 on non-existent
 * route bindings, and that the file is physically persisted on the
 * private disk.
 */
class DocumentHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');
    }

    public function test_post_upload_from_lead_show_persists_file_and_redirects(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('cotizacion.pdf', 2, 'application/pdf'),
            ]);

        $response->assertRedirect(route('leads.show', $lead));
        $response->assertSessionHas('status');

        $document = Document::query()
            ->where('docable_type', Lead::class)
            ->where('docable_id', $lead->id)
            ->first();

        $this->assertNotNull($document);
        $this->assertSame('cotizacion.pdf', $document->name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertTrue(Storage::disk('docs')->exists($document->path));
    }

    public function test_vendedor_without_documents_upload_perm_is_forbidden(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        // Build a user that has zero permissions, simulating a fresh account
        // or one whose role lost documents.upload.
        $stranger = User::factory()->create(['is_active' => true]);
        // No role assignment: no documents.* permissions.

        $response = $this->actingAs($stranger)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('cotizacion.pdf', 2, 'application/pdf'),
            ]);

        $response->assertForbidden();
        $this->assertSame(0, Document::count());
    }

    public function test_download_returns_200_with_correct_content_type(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        // Create a Document row via the public HTTP path (no factory
        // exists for the documents table yet).
        $uploaded = $this->actingAs($this->salespersonOne)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('cotizacion.pdf', 1, 'application/pdf'),
            ]);
        $uploaded->assertRedirect();

        $document = Document::query()->firstOrFail();

        $response = $this->actingAs($this->salespersonOne)
            ->get(route('documents.download', $document));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        // The local stream keeps delivering the original file name.
        $response->assertDownload('cotizacion.pdf');
    }

    public function test_download_redirects_to_a_short_lived_signed_url_when_the_docs_disk_is_cloud_backed(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('cotizacion.pdf', 1, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = Document::query()->firstOrFail();

        $this->useCloudDocsDisk();

        $response = $this->actingAs($this->salespersonOne)
            ->get(route('documents.download', $document));

        // The bytes no longer pass through the application: the authorized
        // download is a redirect to a short-lived signed URL.
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('X-Amz-Signature=', $location);
        $this->assertStringContainsString($document->path, $location);

        // The download identity survives the redirect: the signed URL carries
        // the intended name and type as object-response overrides, so S3 serves
        // the same name and content type the streamed branch would.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('attachment; filename="cotizacion.pdf"', $query['response-content-disposition'] ?? null);
        $this->assertSame('application/pdf', $query['response-content-type'] ?? null);
    }

    public function test_a_signing_failure_streams_the_document_instead_of_failing(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('cotizacion.pdf', 1, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = Document::query()->firstOrFail();

        // The disk advertises temporary URLs but throws while signing. The
        // download must degrade to streaming, not to a 500.
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('providesTemporaryUrls')->andReturn(true);
        $disk->shouldReceive('temporaryUrl')->andThrow(new RuntimeException('signing is broken'));
        $disk->shouldReceive('download')->once()->andReturn(
            new StreamedResponse(function (): void {
                echo '%PDF-1.4';
            }, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="cotizacion.pdf"',
            ]),
        );
        Storage::set('docs', $disk);

        $response = $this->actingAs($this->salespersonOne)
            ->get(route('documents.download', $document));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-1.4', $response->streamedContent());
    }

    public function test_a_refused_download_never_receives_a_signed_url(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('cotizacion.pdf', 1, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = Document::query()->firstOrFail();

        $this->useCloudDocsDisk();

        // A vendedor who does not own the subject is refused before any URL
        // could be built.
        $response = $this->actingAs($this->salespersonTwo)
            ->get(route('documents.download', $document));

        $response->assertForbidden();
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_delete_succeeds_for_uploader_and_returns_redirect(): void
    {
        Storage::fake('docs');
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post(route('leads.documents.store', $lead), [
                'file' => UploadedFile::fake()->create('borrar.pdf', 1, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = Document::query()->firstOrFail();

        $response = $this->actingAs($this->salespersonOne)
            ->delete(route('documents.destroy', $document));

        $response->assertRedirect();
        $this->assertSame(0, Document::withTrashed()->count());
        $this->assertFalse(Storage::disk('docs')->exists($document->path));
    }

    public function test_deleting_a_document_referenced_by_a_course_certificate_is_refused_and_loses_nothing(): void
    {
        Storage::fake('docs');

        [$academic, $document] = $this->courseCertificate();
        $path = (string) $document->path;

        $response = $this->actingAs($this->salespersonOne)
            ->delete(route('documents.destroy', $document));

        // Fail closed with a clear status instead of a half-finished delete:
        // the old order destroyed the file and THEN hit the course foreign key.
        $response->assertStatus(409);

        $this->assertTrue(Storage::disk('docs')->exists($path));
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'path' => $path]);
        $this->assertSame($document->id, (int) $academic->fresh()->document_id);
    }

    public function test_non_existent_subject_returns_404_on_upload(): void
    {
        Storage::fake('docs');

        // Route model binding uses Lead::find($id); missing id => 404.
        $response = $this->actingAs($this->admin)
            ->post('/leads/999999/documents', [
                'file' => UploadedFile::fake()->create('x.pdf', 1, 'application/pdf'),
            ]);

        $response->assertNotFound();
    }

    public function test_non_existent_document_returns_404_on_download(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/documents/999999/download');

        $response->assertNotFound();
    }

    /**
     * Swap the `docs` disk for a real S3 adapter. The adapter signs URLs
     * locally, so no network and no real credentials are involved.
     */
    private function useCloudDocsDisk(): void
    {
        Storage::set('docs', Storage::createS3Driver([
            'driver' => 's3',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'secret',
            'region' => 'us-east-1',
            'bucket' => 'crm-files',
            'root' => 'private/docs',
            'throw' => false,
        ]));
    }

    /**
     * A current certificate plus the private `documents` row it references:
     * exactly what the Documents catalogue lists and what the course module
     * still shows as the current PDF.
     *
     * @return array{0: CourseAcademicDocument, 1: Document}
     */
    private function courseCertificate(): array
    {
        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-HTTP-'.str()->upper(str()->random(8)),
            'issue_date' => now()->toDateString(),
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        $path = "course-academic-documents/{$academic->course_enrollment_id}/{$academic->code}.pdf";
        Storage::disk('docs')->put($path, '%PDF certificado de prueba HTTP');

        $document = Document::query()->create([
            'docable_type' => $academic->getMorphClass(),
            'docable_id' => $academic->id,
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 32,
            'uploaded_by' => $this->salespersonOne->id,
            'uploaded_at' => now(),
        ]);

        $academic->forceFill(['document_id' => $document->id])->save();

        return [$academic->fresh(), $document];
    }
}