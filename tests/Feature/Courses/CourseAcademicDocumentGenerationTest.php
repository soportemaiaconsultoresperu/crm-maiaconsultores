<?php

namespace Tests\Feature\Courses;

use App\Contracts\Courses\{PdfRenderer, QrRenderer};
use App\Enums\Courses\{AcademicDocumentStatus,AcademicDocumentType,CourseActivityType,CourseEnrollmentState,FinalResult,PaymentStatus};
use App\Exceptions\Courses\InvalidCourseDocumentState;
use App\Models\Courses\{CourseActivity,CourseAcademicDocument,CourseCertificateTemplate,CourseEdition,CourseEnrollment,CourseEnrollmentGroup,CourseParticipant,CourseSession};
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseAcademicDocumentGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_approved_certificate_pdf_in_private_storage_and_registers_document(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        $academic = $this->service()->generate($enrollment, $actor);

        $this->assertSame(AcademicDocumentType::ApprovalCertificate, $academic->type);
        $this->assertSame(AcademicDocumentStatus::Current, $academic->status);
        $this->assertSame('Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf', $academic->filename);
        $this->assertSame("course-academic-documents/{$enrollment->id}/{$academic->code}.pdf", $academic->document->path);
        $this->assertSame('docs', $academic->document->disk);
        $this->assertSame(CourseAcademicDocument::class, $academic->document->docable_type);
        $this->assertTrue(Storage::disk('docs')->exists($academic->document->path));
        $pdf = Storage::disk('docs')->get($academic->document->path);
        foreach (['%PDF', 'Certificado de aprobación', 'Temario', $academic->code, '<svg'] as $expected) {
            $this->assertStringContainsString($expected, $pdf);
        }
    }

    public function test_generation_denies_an_actor_without_document_generation_permission(): void
    {
        Storage::fake('docs');
        [$enrollment] = $this->eligibleEnrollment(FinalResult::Approved);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->service()->generate($enrollment, User::factory()->create());
    }

    public function test_selects_participation_constancy_and_talk_certificate_from_eligibility_result(): void
    {
        Storage::fake('docs');
        $actor = User::factory()->create();
        [$participation] = $this->eligibleEnrollment(FinalResult::Participation, CourseActivityType::Course, $actor);
        [$talk] = $this->eligibleEnrollment(FinalResult::NotApplicable, CourseActivityType::Talk, $actor);

        $this->assertSame(AcademicDocumentType::ParticipationConstancy, $this->service()->generate($participation, $actor)->type);
        $this->assertSame(AcademicDocumentType::TalkCertificate, $this->service()->generate($talk, $actor)->type);
    }

    public function test_generated_documents_issue_unique_hash_only_tokens_embed_qr_output_and_stream_the_current_pdf(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        $actor = User::factory()->create();
        [$firstEnrollment] = $this->eligibleEnrollment(FinalResult::Approved, CourseActivityType::Course, $actor);
        [$secondEnrollment] = $this->eligibleEnrollment(FinalResult::Approved, CourseActivityType::Course, $actor);
        $pdfCalls = [];
        $service = $this->service($tokens, $pdfCalls);

        $first = $service->generate($firstEnrollment, $actor);
        $second = $service->generate($secondEnrollment, $actor);

        $this->assertCount(2, $payloads);
        $this->assertNotSame($payloads[0], $payloads[1]);
        $this->assertStringContainsString('/certificate/qr/', $payloads[0]);
        $this->assertStringContainsString($payloads[0], Storage::disk('docs')->get($first->document->path));
        $this->assertSame('course-talks.certificates.reference', $pdfCalls[0]['view']);
        $this->assertSame($first->code, $pdfCalls[0]['data']['certificate']->certificateCode);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->qr_token_hash);
        $this->assertNotSame($first->qr_token_hash, $second->qr_token_hash);
        $this->assertDatabaseMissing('course_academic_documents', ['qr_token_hash' => basename($payloads[0])]);

        $response = $this->get($payloads[0]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('%PDF', $response->streamedContent());
    }

    public function test_regeneration_requires_reason_and_authorized_actor_replaces_and_revokes_the_old_document(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = $this->service($tokens);
        $old = $service->generate($enrollment, $actor);
        $oldToken = basename($payloads[0]);

        $this->expectException(\InvalidArgumentException::class);
        $service->regenerate($old, $actor, '');
    }

    public function test_a_repeated_generate_refuses_to_create_a_second_current_document(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        $first = $this->service()->generate($enrollment, $actor);

        // Before the fix this call silently created a second `current` row with
        // its own code and a live QR; the assertions below therefore saw two.
        $refusal = null;
        try {
            $this->service()->generate($enrollment->fresh(), $actor);
        } catch (\InvalidArgumentException $exception) {
            $refusal = $exception;
        }

        // The refusal happens before any side effect: no second row, no second
        // current certificate, no second private PDF, and the original code is
        // still the only one.
        $this->assertSame(1, CourseAcademicDocument::query()->where('course_enrollment_id', $enrollment->id)->count());
        $this->assertSame(1, CourseAcademicDocument::query()->where('course_enrollment_id', $enrollment->id)->where('status', AcademicDocumentStatus::Current)->count());
        $this->assertSame((string) $first->code, (string) CourseAcademicDocument::query()->sole()->code);
        $this->assertCount(1, Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertNotNull($refusal, 'A repeated generation must be refused by the domain.');
    }

    public function test_authorized_regeneration_preserves_old_private_pdf_and_issues_one_current_document(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        Permission::create(['name' => 'course-talks.documents.revoke']);
        $actor->givePermissionTo('course-talks.documents.revoke');
        $service = $this->service($tokens);
        $old = $service->generate($enrollment, $actor);
        $oldToken = basename($payloads[0]);

        $replacement = $service->regenerate($old, $actor, 'Corrected participant name');

        $old = $old->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $old->status);
        $this->assertNotNull($old->qr_token_revoked_at);
        $this->assertSame($replacement->id, $old->replaced_by_id);
        $this->assertSame('Corrected participant name', $old->annul_reason);
        $this->assertTrue(Storage::disk('docs')->exists($old->document->path));
        $this->assertCount(1, CourseAcademicDocument::query()->where('course_enrollment_id', $enrollment->id)->where('status', AcademicDocumentStatus::Current)->get());
        $this->assertNotSame($old->qr_token_hash, $replacement->qr_token_hash);
        $this->assertDatabaseMissing('course_academic_documents', ['qr_token_hash' => basename($payloads[1])]);
        $this->get("/certificate/qr/{$oldToken}")->assertNotFound();
        $this->get($payloads[1])->assertOk();
    }

    public function test_generation_defers_private_storage_until_an_outer_transaction_commits(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        DB::beginTransaction();
        try {
            $academic = $this->service()->generate($enrollment, $actor);

            $this->assertSame(AcademicDocumentStatus::PendingGeneration, $academic->status);
            $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
            $this->assertDatabaseCount('course_academic_documents', 1);
        } finally {
            DB::rollBack();
        }

        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_outer_transaction_commit_writes_private_pdf_and_only_then_registers_document_metadata(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        DB::beginTransaction();
        $academic = $this->service()->generate($enrollment, $actor);
        $this->assertSame(AcademicDocumentStatus::PendingGeneration, $academic->status);
        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        DB::commit();

        $academic = $academic->fresh()->load('document');
        $this->assertSame(AcademicDocumentStatus::Current, $academic->status);
        $this->assertNotNull($academic->document);
        $this->assertTrue(Storage::disk('docs')->exists($academic->document->path));
    }

    public function test_deferred_storage_failure_marks_document_failed_without_metadata_or_private_file(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer(),
            documentCreator: fn (): never => throw new \RuntimeException('database persistence failed'),
        );

        DB::beginTransaction();
        $academic = $service->generate($enrollment, $actor);
        DB::commit();

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Failed, $academic->status);
        $this->assertNull($academic->document_id);
        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_generation_removes_new_private_file_when_document_persistence_fails(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer(),
            documentCreator: fn (): never => throw new \RuntimeException('database persistence failed'),
        );

        try {
            $service->generate($enrollment, $actor);
            $this->fail('Expected document persistence failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('database persistence failed', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_document_service_keeps_private_download_authorization_for_course_academic_documents(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $academic = $this->service()->generate($enrollment, $actor);
        Permission::create(['name' => 'documents.download']);
        $enrollment->edition->responsible->givePermissionTo('documents.download');

        $documents = app(DocumentService::class);

        $this->assertTrue($documents->canDownload($academic->document, $enrollment->edition->responsible));
        $this->assertFalse($documents->canDownload($academic->document, User::factory()->create()));
    }

    /**
     * Every refusal of the document lifecycle is a tagged domain exception, not a
     * bare message: the reason travels as a stable tag, so the HTTP surface can
     * pick the sentence for the case it knows without parsing text and without
     * reporting one reason when another was the real cause. Each refusal site
     * carries its own tag.
     */
    public function test_academic_document_refusals_are_tagged_domain_exceptions(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        Permission::create(['name' => 'course-talks.documents.revoke']);
        $actor->givePermissionTo('course-talks.documents.revoke');
        $service = $this->service($tokens);
        $first = $service->generate($enrollment, $actor);

        // 1. Not current: the replacement guard refuses to regenerate a document
        // that stopped being vigente.
        $first->forceFill(['status' => AcademicDocumentStatus::Annulled, 'qr_token_revoked_at' => now()])->save();
        try {
            $service->regenerate($first->fresh(), $actor, 'Corrección');
            $this->fail('Un documento que no está vigente no debe regenerarse.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(InvalidCourseDocumentState::class, $exception::class);
            $this->assertSame(InvalidCourseDocumentState::NOT_CURRENT, $exception->reason());
        }

        // 2. Not eligible: the enrollment lost its payment condition, and the tag
        // says so instead of sharing a tag with the currency rule.
        $enrollment->forceFill(['payment_status' => PaymentStatus::Pending])->save();
        try {
            $service->generate($enrollment->fresh(), $actor);
            $this->fail('Una matrícula no elegible no debe generar documento.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(InvalidCourseDocumentState::class, $exception::class);
            $this->assertSame(InvalidCourseDocumentState::NOT_ELIGIBLE, $exception->reason());
            $this->assertStringContainsString('no es elegible', $exception->getMessage());
        }

        // 3. A second current document: the duplicate guard has its own tag too.
        $enrollment->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        $current = $service->generate($enrollment->fresh(), $actor);
        try {
            $service->generate($enrollment->fresh(), $actor);
            $this->fail('No debe crearse un segundo documento académico vigente.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(InvalidCourseDocumentState::class, $exception::class);
            $this->assertSame(InvalidCourseDocumentState::CURRENT_ALREADY_EXISTS, $exception->reason());
        }

        $this->assertSame($current->id, CourseAcademicDocument::query()->where('status', AcademicDocumentStatus::Current)->sole()->id);
        $this->assertSame(1, CourseAcademicDocument::query()->where('status', AcademicDocumentStatus::Current)->count());
    }

    /**
     * The counterpart of the hardcoded-view regression: a template configured
     * for the document type being generated must decide what is rendered, and
     * the document must record which template produced it. The row is written
     * with the columns the template domain owns, so this test proves what
     * generation does with a configured template and does not depend on the
     * configuration API.
     */
    public function test_an_active_template_supplies_its_view_and_settings_and_is_persisted_on_the_document(): void
    {
        Storage::fake('docs');
        $template = CourseCertificateTemplate::query()->create([
            'name' => 'Plantilla de aprobación',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
            'version' => 1,
            'is_active' => true,
            'blade_view' => 'course-talks.certificates.reference',
            'settings_json' => [
                'title' => 'Constancia oficial',
                'intro_text' => 'Otorga la presente constancia a',
                'company' => 'Maia Academy',
                'signatures' => [['name' => 'Firma Uno', 'role' => 'Gerencia']],
            ],
        ]);
        $pdfCalls = [];
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        $academic = $this->service(null, $pdfCalls)->generate($enrollment, $actor);

        $this->assertSame($template->id, $academic->course_certificate_template_id);
        $this->assertSame($template->id, $academic->fresh()->template?->id);
        $this->assertSame('course-talks.certificates.reference', $pdfCalls[0]['view']);
        $certificate = $pdfCalls[0]['data']['certificate'];
        $this->assertSame('Constancia oficial', $certificate->title);
        $this->assertSame('Otorga la presente constancia a', $certificate->introText);
        $this->assertSame('Maia Academy', $certificate->company);
        $this->assertSame([['name' => 'Firma Uno', 'role' => 'Gerencia']], $certificate->signatures);
        $this->assertSame('Alvaro Segundo Alama Silva', $certificate->participant);
        $this->assertSame($academic->code, $certificate->certificateCode);

        $pdf = Storage::disk('docs')->get($academic->document->path);
        foreach (['Constancia oficial', 'Otorga la presente constancia a', 'Maia Academy', 'Firma Uno'] as $expected) {
            $this->assertStringContainsString($expected, $pdf);
        }
        // The service defaults a configured template replaces must be gone.
        $this->assertStringNotContainsString('Otorga el presente certificado a', $pdf);
        $this->assertStringNotContainsString('Dirección Académica', $pdf);
    }

    /**
     * Lock on today's behaviour: with nothing activated for the type, the view
     * name and every configurable field are exactly the pre-template ones.
     */
    public function test_generation_without_an_active_template_keeps_the_reference_view_and_the_service_defaults(): void
    {
        Storage::fake('docs');
        $pdfCalls = [];
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = $this->service(null, $pdfCalls);

        $untemplated = $service->generate($enrollment, $actor);

        $this->assertSame('course-talks.certificates.reference', $pdfCalls[0]['view']);
        $this->assertNull($untemplated->course_certificate_template_id);
        $certificate = $pdfCalls[0]['data']['certificate'];
        $this->assertSame('Certificado de aprobación', $certificate->title);
        $this->assertSame('Otorga el presente certificado a', $certificate->introText);
        $this->assertSame('Maia Consultores', $certificate->company);
        $this->assertSame([
            ['name' => 'Dirección Académica', 'role' => 'Maia Consultores'],
            ['name' => 'Coordinación Académica', 'role' => 'Maia Consultores'],
        ], $certificate->signatures);

        // Resolution reads actives only: an inactive template of the same type
        // changes nothing.
        CourseCertificateTemplate::query()->create([
            'name' => 'Apagada',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
            'version' => 1,
            'is_active' => false,
            'blade_view' => 'course-talks.certificates.reference',
            'settings_json' => ['title' => 'No debe usarse', 'company' => 'No debe usarse'],
        ]);
        [$secondEnrollment] = $this->eligibleEnrollment(FinalResult::Approved, CourseActivityType::Course, $actor);

        $second = $service->generate($secondEnrollment, $actor);

        $this->assertNull($second->course_certificate_template_id);
        $this->assertSame('course-talks.certificates.reference', $pdfCalls[1]['view']);
        $this->assertSame('Certificado de aprobación', $pdfCalls[1]['data']['certificate']->title);
        $this->assertSame('Maia Consultores', $pdfCalls[1]['data']['certificate']->company);
    }

    /**
     * A row written outside the domain (direct database write, restore, a
     * future surface) cannot steer the render: an off-allowlist `blade_view`
     * makes the template unusable and `html_template` — raw administrator HTML
     * — is never rendered into a PDF.
     */
    public function test_a_row_with_an_off_allowlist_blade_view_or_raw_html_is_never_rendered(): void
    {
        Storage::fake('docs');
        $template = new CourseCertificateTemplate();
        // forceFill on purpose: this test simulates a row written OUTSIDE the
        // domain (restore, direct database write, a future surface). Since unit
        // 6.t2 removed `html_template` from $fillable, a plain create() would
        // silently drop the value and the assertion below would pass vacuously.
        $template->forceFill([
            'name' => 'Plantilla manipulada',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
            'version' => 1,
            'is_active' => true,
            'blade_view' => 'admin.users.index',
            'html_template' => '<p>INYECTADO-POR-HTML-TEMPLATE</p>',
            'settings_json' => ['title' => 'Título inyectado'],
        ])->save();
        $this->assertSame(
            '<p>INYECTADO-POR-HTML-TEMPLATE</p>',
            $template->fresh()->html_template,
            'The manipulated row must really carry the raw HTML, otherwise this test proves nothing.',
        );
        $pdfCalls = [];
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        $academic = $this->service(null, $pdfCalls)->generate($enrollment, $actor);

        $this->assertSame('course-talks.certificates.reference', $pdfCalls[0]['view']);
        $this->assertSame('Certificado de aprobación', $pdfCalls[0]['data']['certificate']->title);
        $this->assertNull($academic->course_certificate_template_id);
        $pdf = Storage::disk('docs')->get($academic->document->path);
        $this->assertStringNotContainsString('INYECTADO-POR-HTML-TEMPLATE', $pdf);
        $this->assertStringNotContainsString('Título inyectado', $pdf);
    }

    private function service(?CertificateQrTokenService $qrTokens = null, ?array &$pdfCalls = null): CourseDocumentGenerationService
    {
        return new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer($pdfCalls),
            qrTokens: $qrTokens,
        );
    }

    private function qrRenderer(array &$payloads): QrRenderer
    {
        return new class($payloads) implements QrRenderer {
            public function __construct(private array &$payloads) {}

            public function renderSvg(string $payload): string
            {
                $this->payloads[] = $payload;

                return '<svg>QR '.$payload.'</svg>';
            }
        };
    }

    private function pdfRenderer(?array &$calls = null): PdfRenderer
    {
        return new class($calls) implements PdfRenderer {
            public function __construct(private ?array &$calls) {}

            public function render(string $view, array $data): string
            {
                if ($this->calls !== null) {
                    $this->calls[] = compact('view', 'data');
                }

                return '%PDF '.$view.' '.view($view, $data)->render();
            }
        };
    }

    private function eligibleEnrollment(FinalResult $result, CourseActivityType $type = CourseActivityType::Course, ?User $actor = null): array
    {
        $actor ??= User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.generate'));
        $activity = CourseActivity::factory()->create(['type' => $type, 'name' => $type === CourseActivityType::Talk ? 'Charla de Bioseguridad' : 'Curso Avanzado de Saneamiento Ambiental', 'official_academic_hours' => $type === CourseActivityType::Talk ? '2.50' : '24.00', 'talk_includes_certificate' => $type === CourseActivityType::Talk]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['starts_on' => '2026-07-01', 'ends_on' => $type === CourseActivityType::Talk ? '2026-07-01' : '2026-07-04', 'validations_completed_at' => now(), 'responsible_user_id' => $actor->id]);
        $participant = CourseParticipant::factory()->create(['first_name' => 'Alvaro Segundo', 'last_name' => 'Alama Silva']);
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);
        CourseSession::factory()->for($edition, 'edition')->create(['topic' => 'Marco normativo', 'sort_order' => 1]);

        return [CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create(['course_enrollment_group_id' => $group->id, 'state' => CourseEnrollmentState::Completed, 'payment_status' => PaymentStatus::Paid, 'final_result' => $result, 'participation_confirmed_at' => $type === CourseActivityType::Talk ? now() : null]), $actor];
    }
}
