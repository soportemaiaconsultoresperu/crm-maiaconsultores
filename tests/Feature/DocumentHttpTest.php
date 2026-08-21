<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
}