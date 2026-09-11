<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CourseCommercialDocumentService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Slice 6 unit 6.f-1 — authenticated commercial documents of one edition:
 * registration of an external factura/boleta/recibo for one enrollment, the
 * private attachment upload and the edition's document listing.
 *
 * The controller stays thin: CourseEditionPolicy::view authorizes the list, the
 * commercial-documents permission authorizes registration and upload (asked for
 * by both FormRequests and by CourseCommercialDocumentService itself), and the
 * service owns every rule — rate selection, subtotal arithmetic, the
 * enrollment-or-group constraint, the private file storage. This surface only
 * decides how a domain rejection is reported, so no rejection becomes an HTTP
 * 500, and it renders the pre-submit breakdown exactly as the service returned
 * it, never recomputed or rounded in Blade.
 *
 * Commercial delivery actions (email, WhatsApp handoff, confirmation) are unit
 * 6.f-2 and are deliberately absent here.
 */
class CourseCommercialDocumentHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('docs');

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = $this->userWith([
            'course-talks.view',
            'course-talks.commercial-documents.manage',
        ]);

        $this->edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->create([
                'code' => 'CUR-COM-001',
                'name' => 'Curso de comprobantes',
            ]), 'activity')
            ->create(['code' => 'ED-COM-001']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function enrollment(
        string $activityPrice = '100.00',
        string $certificateCharge = '20.00',
        string $discount = '0.00',
        string $lastName = 'Ramos',
        string $documentNumber = '11111111',
        ?CourseEnrollmentGroup $group = null,
    ): CourseEnrollment {
        $participant = CourseParticipant::factory()->create([
            'first_name' => 'Luz',
            'last_name' => $lastName,
            'document_number' => $documentNumber,
            'document_number_norm' => $documentNumber,
        ]);

        return CourseEnrollment::factory()
            ->for($this->edition, 'edition')
            ->for($participant, 'participant')
            ->create([
                'course_enrollment_group_id' => $group?->id,
                'activity_price_amount' => $activityPrice,
                'certificate_charge_amount' => $certificateCharge,
                'discount_amount' => $discount,
            ]);
    }

    /** @param array<string, mixed> $overrides */
    private function group(array $overrides = []): CourseEnrollmentGroup
    {
        return CourseEnrollmentGroup::factory()->for($this->edition, 'edition')->create($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function registerDocument(
        CourseEnrollment $enrollment,
        CommercialDocumentType $type = CommercialDocumentType::Factura,
        array $overrides = [],
        ?User $actor = null,
    ): CourseCommercialDocument {
        return app(CourseCommercialDocumentService::class)->register($type, array_merge([
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
            'series' => 'F001',
            'number' => '00001234',
            'issue_date' => '2026-08-26',
        ], $overrides), $actor ?? $this->manager);
    }

    private function indexUrl(): string
    {
        return route('course-talks.commercial-documents.index', $this->edition);
    }

    /** @param array<string, mixed> $payload */
    private function postRegistration(CourseEnrollment $enrollment, array $payload, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.store', $enrollment), $payload);
    }

    private function postFile(CourseCommercialDocument $document, UploadedFile $file, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.file', $document), [
                'status' => 'registered',
                'file' => $file,
            ]);
    }

    /** @param array<string, mixed> $payload */
    private function postGroupRegistration(CourseEnrollmentGroup $group, array $payload, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.groups.store', $group), $payload);
    }

    private function listingHtml(?User $actor = null): string
    {
        return (string) $this->actingAs($actor ?? $this->manager)->get($this->indexUrl())->assertOk()->getContent();
    }

    /**
     * The rendered breakdown row of one document type for one payer, so the
     * amounts the user saw before submitting can be asserted value by value
     * instead of by bare substring presence on the whole page.
     */
    private function rowBlock(string $html, string $marker): string
    {
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, "The row {$marker} was not rendered.");

        $end = strpos($html, '</tr>', $start);
        $this->assertNotFalse($end, "The row {$marker} is not a complete table row.");

        return substr($html, $start, $end - $start);
    }

    private function breakdownRow(string $html, CourseEnrollment $enrollment, string $type): string
    {
        return $this->rowBlock($html, 'data-testid="course-talks-commercial-breakdown-'.$enrollment->id.'-'.$type.'"');
    }

    private function groupRow(string $html, CourseEnrollmentGroup $group, string $type): string
    {
        return $this->rowBlock($html, 'data-testid="course-talks-commercial-group-breakdown-'.$group->id.'-'.$type.'"');
    }

    public function test_guests_are_redirected_to_login_from_the_commercial_document_routes(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->registerDocument($enrollment);

        $this->get($this->indexUrl())->assertRedirect(route('login'));
        $this->post(route('course-talks.commercial-documents.store', $enrollment), [
            'type' => 'factura',
            'payer_name' => 'Intento anónimo',
            'course_enrollment_id' => $enrollment->id,
        ])->assertRedirect(route('login'));
        $this->post(route('course-talks.commercial-documents.file', $document), ['status' => 'registered'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_commercial_documents', 1);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_the_listing_shows_the_stored_commercial_fields_of_the_edition(): void
    {
        $attached = $this->registerDocument($this->enrollment());
        app(CourseCommercialDocumentService::class)->upload(
            $attached,
            UploadedFile::fake()->create('factura-2026.pdf', 4, 'application/pdf'),
            $this->manager,
        );
        $attached->forceFill([
            'observations' => 'Emitido externamente.',
            'delivery_status' => DeliveryStatus::Sent,
            'last_sent_at' => now()->setTime(9, 15),
        ])->save();

        $this->registerDocument($this->enrollment('100.00', '20.00', '0.00', 'Diaz', '22222222'), CommercialDocumentType::Recibo);

        // A document of another edition must never leak into this listing.
        $this->registerDocument(CourseEnrollment::factory()->create(), CommercialDocumentType::Boleta, [
            'payer_name' => 'Pagador ajeno',
        ]);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Curso de comprobantes')
            ->assertSee('ED-COM-001')
            ->assertSee('Factura')
            ->assertSee('Recibo')
            ->assertSee('Maia Consultores SAC')
            ->assertSee('ruc 20123456789')
            ->assertSee('F001')
            ->assertSee('00001234')
            ->assertSee('26/08/2026')
            ->assertSee('PEN')
            ->assertSee('120.00')
            ->assertSee('0.1800')
            ->assertSee('21.60')
            ->assertSee('141.60')
            ->assertSee('Emitido externamente.')
            ->assertSee('Registrado')
            ->assertSee('Enviado')
            ->assertSee('09:15')
            // Attachment presence: the uploaded one is complete, the recibo is
            // not and therefore offers the upload control.
            ->assertSee('Adjunto cargado')
            ->assertSee('Sin adjunto')
            ->assertSee('Pendiente de archivo')
            ->assertDontSee('Pagador ajeno');
    }

    public function test_the_listing_shows_the_service_breakdown_of_every_type_before_submitting(): void
    {
        $enrollment = $this->enrollment();
        $service = app(CourseCommercialDocumentService::class);

        $html = $this->listingHtml();

        $factura = $this->breakdownRow($html, $enrollment, 'factura');
        foreach ($service->calculateCharges(CommercialDocumentType::Factura, '100.00', '20.00', '0.00') as $value) {
            $this->assertStringContainsString($value, $factura, "The factura breakdown must show the service value {$value}.");
        }

        $recibo = $this->breakdownRow($html, $enrollment, 'recibo');
        foreach ($service->calculateCharges(CommercialDocumentType::Recibo, '100.00', '20.00', '0.00') as $value) {
            $this->assertStringContainsString($value, $recibo, "The recibo breakdown must show the service value {$value}.");
        }
        $this->assertStringContainsString('0.0000', $recibo);
        $this->assertStringContainsString('0.00', $recibo);

        // The breakdown is informational: nothing is registered until the user
        // submits the form.
        $this->assertDatabaseCount('course_commercial_documents', 0);
    }

    public function test_registering_a_factura_persists_the_service_computed_igv_the_listing_showed(): void
    {
        $enrollment = $this->enrollment();
        $shown = $this->breakdownRow($this->listingHtml(), $enrollment, 'factura');

        $response = $this->postRegistration($enrollment, [
            'type' => 'factura',
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
            'series' => 'F001',
            'number' => '00001234',
            'issue_date' => '2026-08-26',
            'observations' => 'Emitido externamente.',
        ]);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $document = CourseCommercialDocument::query()->sole();
        $this->assertSame(CommercialDocumentType::Factura, $document->type);
        $this->assertSame($enrollment->id, $document->course_enrollment_id);
        $this->assertNull($document->course_enrollment_group_id);
        $this->assertSame('120.00', $document->subtotal_amount);
        $this->assertSame('0.1800', $document->igv_rate);
        $this->assertSame('21.60', $document->igv_amount);
        $this->assertSame('141.60', $document->total_amount);
        $this->assertSame('PEN', $document->currency);
        $this->assertSame('F001', $document->series);
        $this->assertSame('00001234', $document->number);
        $this->assertSame('Maia Consultores SAC', $document->payer_name);
        $this->assertSame('ruc', $document->payer_document_type);
        $this->assertSame('20123456789', $document->payer_document_number);
        $this->assertSame('2026-08-26', $document->issue_date?->toDateString());
        // Registration never claims a file it does not have.
        $this->assertSame('pending_file', $document->status);
        $this->assertNull($document->document_id);

        foreach ([
            $document->subtotal_amount,
            $document->igv_rate,
            $document->igv_amount,
            $document->total_amount,
        ] as $value) {
            $this->assertStringContainsString($value, $shown, "The pre-submit breakdown must show {$value} as it was persisted.");
        }

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Pendiente de archivo')
            ->assertSee('Sin adjunto');
    }

    public function test_registering_a_recibo_stores_a_zero_igv_rate(): void
    {
        $enrollment = $this->enrollment();
        $shown = $this->breakdownRow($this->listingHtml(), $enrollment, 'recibo');

        $response = $this->postRegistration($enrollment, [
            'type' => 'recibo',
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Cliente final',
        ]);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $document = CourseCommercialDocument::query()->sole();
        $this->assertSame(CommercialDocumentType::Recibo, $document->type);
        $this->assertSame('120.00', $document->subtotal_amount);
        $this->assertSame('0.0000', $document->igv_rate);
        $this->assertSame('0.00', $document->igv_amount);
        $this->assertSame('120.00', $document->total_amount);

        foreach ([
            $document->igv_rate,
            $document->igv_amount,
            $document->total_amount,
        ] as $value) {
            $this->assertStringContainsString($value, $shown, "The recibo breakdown must show {$value} as it was persisted.");
        }
    }

    public function test_a_negative_subtotal_is_refused_with_a_visible_error_and_no_row(): void
    {
        // Discount larger than the charged total: the service is the only place
        // that decides this, and it refuses before any row exists.
        $enrollment = $this->enrollment('100.00', '20.00', '150.00');

        $html = $this->listingHtml();
        $this->assertStringContainsString('data-testid="course-talks-commercial-breakdown-error-'.$enrollment->id.'"', $html);
        $this->assertStringContainsString('El subtotal no puede ser negativo.', $html);
        $this->assertStringNotContainsString('data-testid="course-talks-commercial-breakdown-'.$enrollment->id.'-factura"', $html);
        $this->assertStringNotContainsString('data-testid="course-talks-commercial-form-'.$enrollment->id.'"', $html);

        $response = $this->postRegistration($enrollment, [
            'type' => 'factura',
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
        ]);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('commercial_document');

        $this->assertDatabaseCount('course_commercial_documents', 0);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('El subtotal no puede ser negativo.');
    }

    public function test_a_group_target_posted_to_the_enrollment_endpoint_is_refused_by_the_service(): void
    {
        $enrollment = $this->enrollment();
        $group = CourseEnrollmentGroup::factory()
            ->for($this->edition, 'edition')
            ->create(['payer_name' => 'Empresa pagadora']);

        // No group breakdown can be computed by the service yet, so this surface
        // never posts a group target and offers no group control. The payload
        // shape is not the guard: the service owns the exactly-one-target rule
        // and refuses the extra target the endpoint already owns.
        $response = $this->postRegistration($enrollment, [
            'type' => 'boleta',
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Empresa pagadora',
        ]);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('commercial_document');

        $this->assertDatabaseCount('course_commercial_documents', 0);

        // The render check is a second submission: asserting the session bag
        // consumes the pending flash for the next render, so the visible message
        // is proven by following the redirect of a rejected post instead.
        $this->actingAs($this->manager)->followingRedirects()->post(
            route('course-talks.commercial-documents.store', $enrollment),
            [
                'type' => 'boleta',
                'course_enrollment_group_id' => $group->id,
                'payer_name' => 'Empresa pagadora',
            ],
        )->assertSee('Seleccione exactamente una matrícula o grupo de matrícula.');

        $this->assertDatabaseCount('course_commercial_documents', 0);
    }

    public function test_a_submission_with_both_or_neither_target_or_an_unknown_type_creates_no_row(): void
    {
        $enrollment = $this->enrollment();
        $group = CourseEnrollmentGroup::factory()->for($this->edition, 'edition')->create();

        $both = $this->postRegistration($enrollment, [
            'type' => 'boleta',
            'payer_name' => 'Dos destinos',
            'course_enrollment_id' => $enrollment->id,
            'course_enrollment_group_id' => $group->id,
        ]);
        $both->assertRedirect($this->indexUrl());
        $both->assertSessionHasErrors('course_enrollment_id');

        $neither = $this->postRegistration($enrollment, [
            'type' => 'boleta',
            'payer_name' => 'Sin destino',
        ]);
        $neither->assertRedirect($this->indexUrl());
        $neither->assertSessionHasErrors(['course_enrollment_id', 'course_enrollment_group_id']);

        $unknownType = $this->postRegistration($enrollment, [
            'type' => 'nota-de-venta',
            'payer_name' => 'Tipo inválido',
            'course_enrollment_id' => $enrollment->id,
        ]);
        $unknownType->assertRedirect($this->indexUrl());
        $unknownType->assertSessionHasErrors('type');

        $this->assertDatabaseCount('course_commercial_documents', 0);
    }

    public function test_uploading_attaches_the_private_file_to_the_right_document(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->registerDocument($enrollment);

        $attached = $this->registerDocument(
            $this->enrollment('100.00', '20.00', '0.00', 'Diaz', '22222222'),
            CommercialDocumentType::Recibo,
        );
        app(CourseCommercialDocumentService::class)->upload(
            $attached,
            UploadedFile::fake()->create('recibo-2026.pdf', 4, 'application/pdf'),
            $this->manager,
        );

        $html = $this->listingHtml();
        $this->assertStringContainsString('data-testid="course-talks-commercial-upload-form-'.$document->id.'"', $html);
        $this->assertStringNotContainsString('data-testid="course-talks-commercial-upload-form-'.$attached->id.'"', $html);

        $response = $this->postFile($document, UploadedFile::fake()->create('factura-2026.pdf', 4, 'application/pdf'));

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $document = $document->fresh();
        $this->assertSame('registered', $document->status);

        $file = $document->document;
        $this->assertInstanceOf(Document::class, $file);
        $this->assertSame($document->getMorphClass(), $file->docable_type);
        $this->assertSame($document->id, $file->docable_id);
        $this->assertSame($this->manager->id, $file->uploaded_by);
        $this->assertStringStartsWith('course-commercial-documents/'.$document->id.'/', $file->path);
        $this->assertTrue(Storage::disk('docs')->exists($file->path));
        $this->assertDatabaseCount('documents', 2);
        $this->assertNotSame($file->id, $attached->fresh()->document_id);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Adjunto cargado')
            ->assertSee('Registrado')
            // The pending control is gone once the document has its file.
            ->assertDontSee('data-testid="course-talks-commercial-upload-form-'.$document->id.'"', false);
    }

    public function test_uploading_a_disallowed_file_type_is_refused_with_a_visible_error(): void
    {
        $document = $this->registerDocument($this->enrollment());

        $response = $this->postFile($document, UploadedFile::fake()->create('factura.exe', 1, 'application/x-msdownload'));

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('file');

        $document = $document->fresh();
        $this->assertSame('pending_file', $document->status);
        $this->assertNull($document->document_id);
        $this->assertDatabaseCount('documents', 0);

        // Same session-flash constraint as above: the visible message is proven
        // by following the redirect of a second rejected upload.
        $this->actingAs($this->manager)
            ->followingRedirects()
            ->post(route('course-talks.commercial-documents.file', $document), [
                'status' => 'registered',
                'file' => UploadedFile::fake()->create('factura.exe', 1, 'application/x-msdownload'),
            ])
            ->assertSee('debe ser un archivo de tipo');

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_the_commercial_actions_are_denied_and_not_offered_without_the_commercial_permission(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $enrollment = $this->enrollment();
        $document = $this->registerDocument($enrollment);

        $this->actingAs($viewer)->get($this->indexUrl())->assertOk();

        $this->postRegistration($enrollment, [
            'type' => 'factura',
            'payer_name' => 'Intento sin permiso',
            'course_enrollment_id' => $enrollment->id,
        ], $viewer)->assertForbidden();

        $this->postFile($document, UploadedFile::fake()->create('factura-2026.pdf', 4, 'application/pdf'), $viewer)
            ->assertForbidden();

        $this->assertDatabaseCount('course_commercial_documents', 1);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame('pending_file', $document->fresh()->status);
        $this->assertNull($document->fresh()->document_id);

        $html = $this->listingHtml($viewer);
        foreach (['Registrar comprobante', 'Adjuntar archivo', 'data-testid="course-talks-commercial-form-'.$enrollment->id.'"'] as $control) {
            $this->assertStringNotContainsString($control, $html, "A viewer without the commercial permission must not be offered {$control}.");
        }
    }

    public function test_a_user_without_the_module_permission_cannot_read_the_commercial_surface(): void
    {
        $this->enrollment();
        $this->registerDocument($this->enrollment('100.00', '20.00', '0.00', 'Diaz', '22222222'), CommercialDocumentType::Boleta, [
            'payer_name' => 'Pagador reservado',
        ]);

        $this->actingAs($this->userWith([]))->get($this->indexUrl())->assertForbidden();
    }

    public function test_the_edition_detail_offers_the_commercial_surface_to_a_viewer(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $this->enrollment();

        $editionHtml = (string) $this->actingAs($viewer)
            ->get(route('course-talks.editions.show', $this->edition))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.$this->indexUrl().'"', $editionHtml);
        $this->assertStringContainsString('Comprobantes', $editionHtml);

        // The link mirrors the list requirement (CourseEditionPolicy::view), so
        // it can never answer 403 for the user who sees it.
        $this->actingAs($viewer)->get($this->indexUrl())->assertOk();
    }

    public function test_each_enrollment_gets_the_breakdown_of_its_own_charges(): void
    {
        $first = $this->enrollment('100.00', '20.00', '0.00');
        $second = $this->enrollment('200.00', '0.00', '10.00', 'Diaz', '22222222');

        $html = $this->listingHtml();

        $firstRow = $this->breakdownRow($html, $first, 'factura');
        $this->assertStringContainsString('120.00', $firstRow);
        $this->assertStringContainsString('21.60', $firstRow);
        $this->assertStringContainsString('141.60', $firstRow);

        $secondRow = $this->breakdownRow($html, $second, 'factura');
        $this->assertStringContainsString('190.00', $secondRow);
        $this->assertStringContainsString('34.20', $secondRow);
        $this->assertStringContainsString('224.20', $secondRow);
        // The charges are not shared between enrollments of the same edition.
        $this->assertStringNotContainsString('141.60', $secondRow);
    }

    public function test_a_payload_naming_another_enrollment_cannot_retarget_the_document(): void
    {
        $target = $this->enrollment();
        $other = $this->enrollment('200.00', '0.00', '0.00', 'Diaz', '22222222');

        $this->postRegistration($target, [
            'type' => 'boleta',
            'payer_name' => 'Maia Consultores SAC',
            'course_enrollment_id' => $other->id,
        ])->assertRedirect($this->indexUrl());

        $document = CourseCommercialDocument::query()->sole();
        $this->assertSame($target->id, $document->course_enrollment_id);
        // And the money is the target enrollment's, not the named one's.
        $this->assertSame('120.00', $document->subtotal_amount);
        $this->assertSame('141.60', $document->total_amount);
    }

    public function test_missing_enrollment_and_document_ids_are_not_found(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->registerDocument($enrollment);

        $this->actingAs($this->manager)
            ->post(route('course-talks.commercial-documents.store', 999999), [
                'type' => 'factura',
                'payer_name' => 'Destino inexistente',
                'course_enrollment_id' => 999999,
            ])
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->post(route('course-talks.commercial-documents.file', 999999), ['status' => 'registered'])
            ->assertNotFound();

        $this->assertSame('pending_file', $document->fresh()->status);
        $this->assertDatabaseCount('course_commercial_documents', 1);
    }

    public function test_the_group_section_shows_the_service_group_breakdown_and_registers_the_group_comprobante(): void
    {
        $group = $this->group([
            'payer_name' => 'Empresa Grupo SAC',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20999999999',
        ]);
        $this->enrollment('100.00', '20.00', '0.00', 'Ramos', '11111111', $group);
        $this->enrollment('50.00', '0.00', '10.00', 'Diaz', '22222222', $group);
        $service = app(CourseCommercialDocumentService::class);

        $html = $this->listingHtml();
        $shown = $this->groupRow($html, $group, 'factura');
        foreach ($service->calculateGroupCharges(CommercialDocumentType::Factura, $group) as $value) {
            $this->assertStringContainsString($value, $shown, "The group breakdown must show the service value {$value}.");
        }

        // The group form carries the group's own payer and posts to the group endpoint.
        $this->assertStringContainsString('value="Empresa Grupo SAC"', $html);
        $this->assertStringContainsString('value="20999999999"', $html);
        $this->assertStringContainsString(route('course-talks.commercial-documents.groups.store', $group), $html);
        // The breakdown is informational: nothing is registered until the user submits.
        $this->assertDatabaseCount('course_commercial_documents', 0);

        $this->postGroupRegistration($group, [
            'type' => 'factura',
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Empresa Grupo SAC',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20999999999',
            'series' => 'F001',
            'number' => '00005678',
        ])->assertRedirect($this->indexUrl())->assertSessionHas('status');

        $document = CourseCommercialDocument::query()->sole();
        $this->assertSame($group->id, $document->course_enrollment_group_id);
        $this->assertNull($document->course_enrollment_id);
        $this->assertSame('160.00', $document->subtotal_amount);
        $this->assertSame('0.1800', $document->igv_rate);
        $this->assertSame('28.80', $document->igv_amount);
        $this->assertSame('188.80', $document->total_amount);
        $this->assertSame('Empresa Grupo SAC', $document->payer_name);
        $this->assertSame('pending_file', $document->status);

        foreach ([
            $document->subtotal_amount,
            $document->igv_rate,
            $document->igv_amount,
            $document->total_amount,
        ] as $value) {
            $this->assertStringContainsString($value, $shown, "The pre-submit group breakdown must show {$value} as it was persisted.");
        }
    }

    public function test_a_group_that_cannot_be_billed_shows_the_service_reason_instead_of_a_registration_form(): void
    {
        $group = $this->group(['payer_name' => 'Grupo sin matrículas']);

        try {
            app(CourseCommercialDocumentService::class)->calculateGroupCharges(CommercialDocumentType::Factura, $group);
            $this->fail('A group without billable enrollments must not produce a breakdown.');
        } catch (InvalidArgumentException $exception) {
            $reason = $exception->getMessage();
        }

        $html = $this->listingHtml();
        $this->assertStringContainsString('data-testid="course-talks-commercial-group-error-'.$group->id.'"', $html);
        $this->assertStringContainsString($reason, $html);
        $this->assertStringNotContainsString('data-testid="course-talks-commercial-group-form-'.$group->id.'"', $html);
        $this->assertStringNotContainsString('data-testid="course-talks-commercial-group-breakdown-'.$group->id.'-factura"', $html);

        // The same group cannot be written through the endpoint either.
        $this->postGroupRegistration($group, [
            'type' => 'factura',
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Grupo sin matrículas',
        ])->assertRedirect($this->indexUrl())->assertSessionHasErrors('commercial_document');

        $this->assertDatabaseCount('course_commercial_documents', 0);
    }

    public function test_the_group_registration_is_denied_and_not_offered_without_the_commercial_permission(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $group = $this->group(['payer_name' => 'Empresa Grupo SAC']);
        $this->enrollment('100.00', '20.00', '0.00', 'Ramos', '11111111', $group);

        $this->actingAs($viewer)->get($this->indexUrl())->assertOk();

        $this->postGroupRegistration($group, [
            'type' => 'factura',
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Intento sin permiso',
        ], $viewer)->assertForbidden();

        $this->assertDatabaseCount('course_commercial_documents', 0);

        $html = $this->listingHtml($viewer);
        foreach ([
            'Registrar comprobante por grupo',
            'data-testid="course-talks-commercial-group-form-'.$group->id.'"',
            'data-testid="course-talks-commercial-group-breakdown-'.$group->id.'-factura"',
        ] as $control) {
            $this->assertStringNotContainsString($control, $html, "A viewer without the commercial permission must not be offered {$control}.");
        }
    }
}
