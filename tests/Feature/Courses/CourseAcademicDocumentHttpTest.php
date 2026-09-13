<?php

namespace Tests\Feature\Courses;

use App\Contracts\Courses\PdfRenderer;
use App\Contracts\Courses\QrRenderer;
use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\DeliveryStatus;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Http\Requests\CourseTalks\AnnulAcademicDocumentRequest;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6.e-1 — authenticated academic document lifecycle of one edition.
 *
 * The controller stays thin: CourseEditionPolicy::view authorizes the list,
 * `generate` authorizes generation and regeneration, `revoke` authorizes
 * annulment, and CourseDocumentGenerationService / CertificateQrTokenService
 * own every rule — eligibility evaluation, document type selection, filename and
 * code generation, the private PDF, the QR token, the replacement path and the
 * annulment columns. The surface only decides how a domain rejection is
 * reported, so no rejection becomes an HTTP 500.
 *
 * The PDF and QR adapters are faked in the container: this class exercises the
 * HTTP surface, not DomPDF or the QR encoder, and the real adapters keep their
 * own service-level coverage.
 */
class CourseAcademicDocumentHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('docs');

        $this->app->instance(PdfRenderer::class, new class implements PdfRenderer
        {
            public function render(string $view, array $data): string
            {
                return '%PDF documento académico de prueba';
            }
        });

        $this->app->instance(QrRenderer::class, new class implements QrRenderer
        {
            public function renderSvg(string $payload): string
            {
                return '<svg>QR</svg>';
            }
        });

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = $this->userWith([
            'course-talks.view',
            'course-talks.documents.generate',
            'course-talks.documents.revoke',
        ]);

        $this->edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->create([
                'type' => CourseActivityType::Course,
                'code' => 'CUR-DOC-001',
                'name' => 'Curso de documentos',
            ]), 'activity')
            ->create([
                'code' => 'ED-DOC-001',
                'validations_completed_at' => now(),
            ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function enrollment(
        string $lastName = 'Ramos',
        string $documentNumber = '11111111',
        FinalResult $result = FinalResult::Approved,
        PaymentStatus $payment = PaymentStatus::Paid,
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
                'payment_status' => $payment,
                'final_result' => $result,
            ]);
    }

    private function currentDocument(CourseEnrollment $enrollment, string $code = 'CERT-APR-HTTP-001'): CourseAcademicDocument
    {
        $document = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => $code,
            'issue_date' => now()->toDateString(),
            'filename' => 'Certificado_Luz Ramos_Curso de documentos_01-02.07.26_Maia Consultores.pdf',
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        // A real private PDF behind the row, so the public QR route can be used
        // to prove that annulment actually revokes delivery of the file.
        $path = "course-academic-documents/{$enrollment->id}/{$code}.pdf";
        Storage::disk('docs')->put($path, '%PDF documento académico de prueba');
        $file = Document::query()->create([
            'docable_type' => $document->getMorphClass(),
            'docable_id' => $document->id,
            'name' => $document->filename,
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 37,
            'uploaded_by' => $this->manager->id,
            'uploaded_at' => now(),
        ]);
        $document->forceFill(['document_id' => $file->id])->save();

        return $document->refresh();
    }

    private function indexUrl(): string
    {
        return route('course-talks.documents.index', $this->edition);
    }

    private function generate(CourseEnrollment $enrollment, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager)
            ->post(route('course-talks.documents.generate', $enrollment));
    }

    private function regenerate(CourseAcademicDocument $document, string $reason, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.documents.regenerate', $document), ['reason' => $reason]);
    }

    private function annul(CourseAcademicDocument $document, string $reason, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.documents.annul', $document), ['reason' => $reason]);
    }

    public function test_guests_are_redirected_to_login_from_the_document_routes(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        $this->get($this->indexUrl())->assertRedirect(route('login'));
        $this->post(route('course-talks.documents.generate', $enrollment))->assertRedirect(route('login'));
        $this->post(route('course-talks.documents.regenerate', $document), ['reason' => 'Corrección'])->assertRedirect(route('login'));
        $this->post(route('course-talks.documents.annul', $document), ['reason' => 'Corrección'])->assertRedirect(route('login'));

        $this->assertSame(AcademicDocumentStatus::Current, $document->fresh()->status);
        $this->assertDatabaseCount('course_academic_documents', 1);
    }

    public function test_document_list_shows_the_expected_type_eligibility_and_recorded_document(): void
    {
        $enrollment = $this->enrollment();
        $this->generate($enrollment)->assertRedirect($this->indexUrl());

        $document = CourseAcademicDocument::query()->sole();
        $document->forceFill([
            'delivery_status' => DeliveryStatus::Sent,
            'last_sent_at' => now()->setTime(10, 30),
        ])->save();

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Curso de documentos')
            ->assertSee('ED-DOC-001')
            ->assertSee('Ramos, Luz')
            ->assertSee('11111111')
            ->assertSee('Certificado de aprobación')
            ->assertSee('Elegible')
            ->assertSee($document->code)
            ->assertSee('Vigente')
            ->assertSee('Enviado')
            ->assertSee('10:30')
            ->assertSee('Regenerar')
            ->assertSee('Anular')
            // A vigente document is not generated again: the lifecycle actions
            // for it are regeneration and annulment.
            ->assertDontSee('Generar documento');
    }

    public function test_document_list_shows_the_missing_conditions_before_the_user_acts(): void
    {
        $this->edition->forceFill(['validations_completed_at' => null])->save();
        $this->enrollment(payment: PaymentStatus::Pending);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('No elegible')
            ->assertSee('Condiciones pendientes')
            ->assertSee('Pago pendiente de completar')
            ->assertSee('Validaciones de la edición pendientes')
            ->assertSee('La generación estará disponible cuando se cumplan las condiciones pendientes.')
            ->assertDontSee('Elegible')
            // Generation is not offered while the conditions are pending: the
            // user sees why it is unavailable instead of discovering it through
            // a failure. The server still refuses the action (see the refusal
            // test below), because a stale or tampered payload can reach it.
            ->assertDontSee('Generar documento');

        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_generation_creates_a_current_document_for_an_eligible_enrollment(): void
    {
        $enrollment = $this->enrollment();

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Generar documento');

        $response = $this->generate($enrollment);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $document = CourseAcademicDocument::query()->sole();
        $this->assertSame(AcademicDocumentType::ApprovalCertificate, $document->type);
        $this->assertSame(AcademicDocumentStatus::Current, $document->status);
        $this->assertStringStartsWith('CERT-APR-', (string) $document->code);
        $this->assertStringContainsString('Certificado_Luz Ramos_Curso de documentos', (string) $document->filename);
        $this->assertNotNull($document->document);
        $this->assertTrue(Storage::disk('docs')->exists($document->document->path));

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Vigente')
            ->assertSee('Regenerar')
            ->assertSee('Anular');
    }

    public function test_repeated_generation_is_refused_with_a_visible_spanish_error_and_keeps_one_current_document(): void
    {
        $enrollment = $this->enrollment();

        $this->generate($enrollment)->assertRedirect($this->indexUrl());
        $document = CourseAcademicDocument::query()->sole();

        $response = $this->generate($enrollment);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('documents');

        $this->assertSame(1, CourseAcademicDocument::query()->count());
        $this->assertSame(1, CourseAcademicDocument::query()->where('status', AcademicDocumentStatus::Current)->count());
        $this->assertSame($document->id, CourseAcademicDocument::query()->sole()->id);

        $this->actingAs($this->manager)->followingRedirects()
            ->post(route('course-talks.documents.generate', $enrollment))
            ->assertSee('ya cuenta con un documento académico vigente');
    }

    public function test_generation_is_refused_for_an_ineligible_enrollment_with_a_visible_spanish_error(): void
    {
        $enrollment = $this->enrollment(payment: PaymentStatus::Pending);

        $response = $this->generate($enrollment);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('documents');

        $this->assertDatabaseCount('course_academic_documents', 0);

        $this->actingAs($this->manager)->followingRedirects()->post(route('course-talks.documents.generate', $enrollment))
            ->assertSee('no es elegible');
    }

    public function test_regeneration_requires_a_reason(): void
    {
        $enrollment = $this->enrollment();
        $this->generate($enrollment)->assertRedirect($this->indexUrl());
        $document = CourseAcademicDocument::query()->sole();

        $response = $this->regenerate($document, '');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('reason');

        $this->assertSame(AcademicDocumentStatus::Current, $document->fresh()->status);
        $this->assertDatabaseCount('course_academic_documents', 1);
    }

    public function test_regeneration_replaces_the_current_document_with_a_new_current_one(): void
    {
        $enrollment = $this->enrollment();
        $this->generate($enrollment)->assertRedirect($this->indexUrl());
        $original = CourseAcademicDocument::query()->sole();
        $originalCode = (string) $original->code;

        $response = $this->regenerate($original, 'Nombre corregido del participante');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $original = $original->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $original->status);
        $this->assertNotNull($original->qr_token_revoked_at);
        $this->assertSame('Nombre corregido del participante', $original->annul_reason);
        $this->assertSame($this->manager->id, $original->annulled_by);
        $this->assertNotNull($original->replaced_by_id);

        $replacement = CourseAcademicDocument::query()->findOrFail($original->replaced_by_id);
        $this->assertSame(AcademicDocumentStatus::Current, $replacement->status);
        $this->assertNotSame($originalCode, (string) $replacement->code);
        $this->assertNotSame($original->qr_token_hash, $replacement->qr_token_hash);
        $this->assertSame(1, CourseAcademicDocument::query()->where('status', AcademicDocumentStatus::Current)->count());

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Reemplazado')
            ->assertSee($replacement->code);
    }

    public function test_annulment_requires_a_reason(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);
        $token = app(CertificateQrTokenService::class)->createFor($document);

        $response = $this->annul($document, '');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('reason');

        $document = $document->fresh();
        $this->assertSame(AcademicDocumentStatus::Current, $document->status);
        $this->assertNull($document->qr_token_revoked_at);
        $this->get("/certificate/qr/{$token}")->assertOk();
    }

    public function test_annulment_revokes_the_qr_token_and_persists_the_actor_and_reason(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);
        $token = app(CertificateQrTokenService::class)->createFor($document);

        $this->get("/certificate/qr/{$token}")->assertOk();

        $response = $this->annul($document, 'Error en los datos del participante');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $document = $document->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $document->status);
        $this->assertNotNull($document->qr_token_revoked_at);
        $this->assertNotNull($document->annulled_at);
        $this->assertSame($this->manager->id, $document->annulled_by);
        $this->assertSame('Error en los datos del participante', $document->annul_reason);

        // The revocation is real, not cosmetic: the previously working QR link
        // stops streaming the private PDF.
        $this->get("/certificate/qr/{$token}")->assertNotFound();

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Anulado')
            ->assertSee('Error en los datos del participante');
    }

    public function test_an_annulled_or_replaced_document_cannot_be_annulled_again(): void
    {
        $enrollment = $this->enrollment();
        $annulled = $this->currentDocument($enrollment, 'CERT-APR-HTTP-010');
        $this->annul($annulled, 'Motivo original de anulación')->assertRedirect($this->indexUrl());
        $annulled = $annulled->fresh();
        $annulledAt = $annulled->annulled_at;

        $response = $this->annul($annulled, 'Segundo motivo');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('documents');

        $annulled = $annulled->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $annulled->status);
        $this->assertSame('Motivo original de anulación', $annulled->annul_reason);
        $this->assertSame($this->manager->id, $annulled->annulled_by);
        $this->assertSame($annulledAt?->toDateTimeString(), $annulled->annulled_at?->toDateTimeString());

        $replaced = $this->currentDocument($this->enrollment('Diaz', '22222222'), 'CERT-APR-HTTP-011');
        $this->regenerate($replaced, 'Reemplazo previo')->assertRedirect($this->indexUrl());
        $replaced = $replaced->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $replaced->status);

        $response = $this->annul($replaced, 'Anular un reemplazado');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('documents');

        $replaced = $replaced->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $replaced->status);
        $this->assertSame('Reemplazo previo', $replaced->annul_reason);
        $this->assertNotNull($replaced->replaced_by_id);
    }

    public function test_generation_and_regeneration_are_denied_without_the_generate_permission(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        $this->actingAs($viewer)->post(route('course-talks.documents.generate', $enrollment))->assertForbidden();
        $this->actingAs($viewer)->post(route('course-talks.documents.regenerate', $document), ['reason' => 'Corrección'])->assertForbidden();

        $this->assertSame(AcademicDocumentStatus::Current, $document->fresh()->status);
        $this->assertDatabaseCount('course_academic_documents', 1);
    }

    public function test_regeneration_and_annulment_also_require_the_revoke_ability_the_domain_enforces(): void
    {
        // `course-talks.documents.generate` alone is not enough to regenerate:
        // CourseDocumentGenerationService::regenerate() authorizes `revoke` on
        // the document as well, so the domain answers 403 for a generate-only
        // actor and the document stays vigente. The list gates the control on
        // both abilities so no rendered control can reach that denial.
        $generator = $this->userWith(['course-talks.view', 'course-talks.documents.generate']);
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        $this->regenerate($document, 'Corrección', $generator)->assertForbidden();
        $this->annul($document, 'Corrección', $generator)->assertForbidden();

        $document = $document->fresh();
        $this->assertSame(AcademicDocumentStatus::Current, $document->status);
        $this->assertNull($document->qr_token_revoked_at);
        $this->assertDatabaseCount('course_academic_documents', 1);

        $html = (string) $this->actingAs($generator)->get($this->indexUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('Regenerar', $html);
        $this->assertStringNotContainsString('Anular', $html);
    }

    public function test_annulment_is_denied_without_the_revoke_permission(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        $this->annul($document, 'Corrección', $viewer)->assertForbidden();

        $document = $document->fresh();
        $this->assertSame(AcademicDocumentStatus::Current, $document->status);
        $this->assertNull($document->qr_token_revoked_at);
        $this->assertNull($document->annul_reason);
    }

    public function test_missing_enrollment_or_document_ids_are_not_found(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        $this->actingAs($this->manager)->post(route('course-talks.documents.generate', 999999))->assertNotFound();
        $this->actingAs($this->manager)->post(route('course-talks.documents.regenerate', 999999), ['reason' => 'Corrección'])->assertNotFound();
        $this->actingAs($this->manager)->post(route('course-talks.documents.annul', 999999), ['reason' => 'Corrección'])->assertNotFound();

        $this->assertSame(AcademicDocumentStatus::Current, $document->fresh()->status);
        $this->assertDatabaseCount('course_academic_documents', 1);
    }

    public function test_a_viewer_without_module_permission_cannot_read_the_document_surface(): void
    {
        $outsider = $this->userWith([]);
        $this->enrollment();

        $this->actingAs($outsider)->get($this->indexUrl())->assertForbidden();
    }

    public function test_the_edition_detail_offers_the_document_surface_to_a_viewer_without_lifecycle_controls(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $enrollment = $this->enrollment();
        $this->currentDocument($enrollment);

        $editionHtml = (string) $this->actingAs($viewer)->get(route('course-talks.editions.show', $this->edition))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$this->indexUrl().'"', $editionHtml);

        // The list read uses CourseEditionPolicy::view, the same ability the
        // link is gated on, so the link never answers 403; the lifecycle
        // controls stay behind their own abilities.
        $listHtml = (string) $this->actingAs($viewer)->get($this->indexUrl())->assertOk()->getContent();
        foreach (['Generar documento', 'Regenerar', 'Anular'] as $control) {
            $this->assertStringNotContainsString($control, $listHtml, "A viewer must not be offered the {$control} control.");
        }
    }

    /**
     * A regeneration refused for a reason OTHER than "not current" must report
     * that real reason. Here the document is still vigente and the refusal comes
     * from the enrollment losing its payment condition after the certificate was
     * issued, so the not-current sentence would be false.
     */
    public function test_regeneration_refused_by_eligibility_reports_the_real_reason_instead_of_the_not_current_constant(): void
    {
        $enrollment = $this->enrollment();
        $this->generate($enrollment)->assertRedirect($this->indexUrl());
        $document = CourseAcademicDocument::query()->sole();

        // The payment was reversed after the certificate was issued: the document
        // is still current, so eligibility — not currency — is what refuses.
        $enrollment->forceFill(['payment_status' => PaymentStatus::Pending])->save();

        $response = $this->actingAs($this->manager)->followingRedirects()
            ->post(route('course-talks.documents.regenerate', $document), ['reason' => 'Corrección del nombre del participante']);

        $response->assertSee('no es elegible');
        $response->assertDontSee('solo un documento vigente puede regenerarse');

        $this->assertSame(AcademicDocumentStatus::Current, $document->fresh()->status);
        $this->assertDatabaseCount('course_academic_documents', 1);
    }

    /**
     * The not-current sentence keeps its own case: a document that stopped being
     * vigente is still reported as such, and the refused regeneration writes
     * nothing at all.
     */
    public function test_regeneration_of_a_document_that_is_no_longer_current_keeps_its_own_sentence(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);
        $this->annul($document, 'Error en los datos del participante')->assertRedirect($this->indexUrl());

        $response = $this->actingAs($this->manager)->followingRedirects()
            ->post(route('course-talks.documents.regenerate', $document->fresh()), ['reason' => 'Reintento sobre un anulado']);

        $response->assertSee('solo un documento vigente puede regenerarse');

        $document = $document->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $document->status);
        $this->assertNull($document->replaced_by_id);
        $this->assertSame('Error en los datos del participante', $document->annul_reason);
        $this->assertDatabaseCount('course_academic_documents', 1);
    }

    /**
     * A comment is only correct while the code it describes still matches it. The
     * annul form's comment claimed the service has no status guard of its own,
     * which stopped being true when CertificateQrTokenService::revoke() started
     * re-reading the persisted status under a lock. Both halves of the corrected
     * claim are asserted: the service really refuses on its own, and the comment
     * says so instead of denying it.
     */
    public function test_the_annul_form_comment_matches_the_service_guard_that_actually_exists(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);
        $this->annul($document, 'Motivo original de anulación')->assertRedirect($this->indexUrl());
        $document = $document->fresh();

        // Reached without the controller's boundary check, the domain refuses the
        // annulment by itself and the annulled row keeps its original reason.
        try {
            app(CertificateQrTokenService::class)->revoke($document, $this->manager, 'Segunda anulación');
            $this->fail('CertificateQrTokenService::revoke() must refuse a document that is not current.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Only a current academic document may be annulled.', $exception->getMessage());
        }

        $document = $document->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $document->status);
        $this->assertSame('Motivo original de anulación', $document->annul_reason);

        $comment = (new \ReflectionClass(AnnulAcademicDocumentRequest::class))->getDocComment();
        $this->assertIsString($comment);
        $this->assertStringNotContainsString('has no such guard', $comment);
        $this->assertStringContainsString('CertificateQrTokenService', $comment);
    }
}
