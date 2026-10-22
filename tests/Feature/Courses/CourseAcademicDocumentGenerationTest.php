<?php

namespace Tests\Feature\Courses;

use App\Contracts\Courses\{PdfRenderer, QrRenderer};
use App\Enums\Courses\{AcademicDocumentStatus,AcademicDocumentType,CourseActivityType,CourseEnrollmentState,FinalResult,PaymentStatus};
use App\Exceptions\Courses\InvalidCourseDocumentState;
use App\Models\Courses\{CourseActivity,CourseAcademicDocument,CourseCertificateTemplate,CourseEdition,CourseEditionTeacher,CourseEnrollment,CourseEnrollmentGroup,CourseParticipant,CourseSession};
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\Courses\DomPdfRenderer;
use App\ViewModels\Courses\CourseCertificateViewModel;
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
        foreach (['%PDF', 'Certificado de aprobación', 'Temario', $academic->code, 'data:image/svg+xml;base64,'] as $expected) {
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
        } catch (InvalidCourseDocumentState $exception) {
                $this->assertSame(InvalidCourseDocumentState::NOT_CURRENT, $exception->reason());
            }

        // 2. Not eligible: the enrollment lost its payment condition, and the tag
        // says so instead of sharing a tag with the currency rule.
        $enrollment->forceFill(['payment_status' => PaymentStatus::Pending])->save();
        try {
            $service->generate($enrollment->fresh(), $actor);
            $this->fail('Una matrícula no elegible no debe generar documento.');
        } catch (InvalidCourseDocumentState $exception) {
                $this->assertSame(InvalidCourseDocumentState::NOT_ELIGIBLE, $exception->reason());
                $this->assertStringContainsString('no es elegible', $exception->getMessage());
            }

        // 3. A second current document: the duplicate guard has its own tag too.
        $enrollment->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        $current = $service->generate($enrollment->fresh(), $actor);
        try {
            $service->generate($enrollment->fresh(), $actor);
            $this->fail('No debe crearse un segundo documento académico vigente.');
        } catch (InvalidCourseDocumentState $exception) {
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

    public function test_certificate_syllabus_uses_sessions_even_when_the_delivery_has_a_manual_syllabus(): void
    {
        Storage::fake('docs');
        $pdfCalls = [];
        [$enrollment, $actor] = $this->enrollmentWithSyllabus(
            baseSyllabus: ['BASE SYLLABUS MUST NOT RENDER'],
            deliverySyllabus: ['MANUAL DELIVERY SYLLABUS MUST NOT RENDER'],
            sessionTopics: ['REAL SESSION TOPIC'],
        );

        $academic = $this->service(null, $pdfCalls)->generate($enrollment, $actor);

        $topics = array_column($pdfCalls[0]['data']['certificate']->syllabus, 'topic');
        $this->assertSame(['REAL SESSION TOPIC'], $topics);
        $this->assertNotContains('MANUAL DELIVERY SYLLABUS MUST NOT RENDER', $topics);
        $this->assertNotContains('BASE SYLLABUS MUST NOT RENDER', $topics);

        $pdf = Storage::disk('docs')->get($academic->document->path);
        $this->assertStringContainsString('REAL SESSION TOPIC', $pdf);
        $this->assertStringNotContainsString('MANUAL DELIVERY SYLLABUS MUST NOT RENDER', $pdf);
        $this->assertStringNotContainsString('BASE SYLLABUS MUST NOT RENDER', $pdf);
    }

    public function test_certificate_syllabus_is_empty_when_the_enrollments_edition_has_no_sessions(): void
    {
        Storage::fake('docs');
        $pdfCalls = [];
        [$enrollment, $actor] = $this->enrollmentWithSyllabus(
            baseSyllabus: ['BASE SYLLABUS MUST NOT RENDER'],
            deliverySyllabus: ['MANUAL DELIVERY SYLLABUS MUST NOT RENDER'],
            sessionTopics: [],
        );

        $academic = $this->service(null, $pdfCalls)->generate($enrollment, $actor);

        $this->assertSame([], $pdfCalls[0]['data']['certificate']->syllabus);

        $pdf = Storage::disk('docs')->get($academic->document->path);
        $this->assertStringContainsString('Temario', $pdf);
        $this->assertStringContainsString('Clase', $pdf);
        $this->assertStringNotContainsString('MANUAL DELIVERY SYLLABUS MUST NOT RENDER', $pdf);
        $this->assertStringNotContainsString('BASE SYLLABUS MUST NOT RENDER', $pdf);
    }

    public function test_certificate_session_speaker_prefers_fk_teacher_then_legacy_text_then_company(): void
    {
        Storage::fake('docs');
        $pdfCalls = [];
        [$enrollment, $actor] = $this->enrollmentWithSyllabus(
            baseSyllabus: ['BASE SYLLABUS MUST NOT RENDER'],
            deliverySyllabus: ['MANUAL DELIVERY SYLLABUS MUST NOT RENDER'],
            sessionTopics: ['Asignada', 'Legado', 'Casa'],
        );
        $teacher = CourseEditionTeacher::query()->create([
            'course_edition_id' => $enrollment->edition->id,
            'display_name' => 'Docente FK',
            'sort_order' => 1,
        ]);
        $sessions = $enrollment->edition->sessions()->orderBy('sort_order')->get();
        $sessions[0]->forceFill(['teacher_id' => $teacher->id, 'teacher_name' => 'Texto ignorado'])->save();
        $sessions[1]->forceFill(['teacher_id' => null, 'teacher_name' => 'Docente legado'])->save();
        $sessions[2]->forceFill(['teacher_id' => null, 'teacher_name' => '   '])->save();

        $this->service(null, $pdfCalls)->generate($enrollment->fresh(), $actor);

        $this->assertSame(
            ['Docente FK', 'Docente legado', 'Maia Consultores'],
            array_column($pdfCalls[0]['data']['certificate']->syllabus, 'speaker'),
        );
        $this->assertSame(['Asignada', 'Legado', 'Casa'], array_column($pdfCalls[0]['data']['certificate']->syllabus, 'topic'));
    }

    public function test_certificate_syllabus_uses_only_the_enrollments_own_edition_sessions(): void
    {
        Storage::fake('docs');
        $pdfCalls = [];
        [$enrollment, $actor] = $this->enrollmentWithSyllabus(
            baseSyllabus: ['BASE SYLLABUS MUST NOT RENDER'],
            deliverySyllabus: ['MANUAL DELIVERY SYLLABUS MUST NOT RENDER'],
            sessionTopics: ['OWN EDITION SESSION'],
        );
        $foreignEdition = CourseEdition::factory()->for($enrollment->edition->activity, 'activity')->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-02',
            'validations_completed_at' => now(),
            'responsible_user_id' => $actor->id,
            'syllabus_override_json' => ['FOREIGN MANUAL SYLLABUS MUST NOT RENDER'],
        ]);
        CourseSession::factory()->for($foreignEdition, 'edition')->create([
            'topic' => 'FOREIGN EDITION SESSION MUST NOT RENDER',
            'sort_order' => 1,
        ]);

        $academic = $this->service(null, $pdfCalls)->generate($enrollment->fresh(), $actor);

        $topics = array_column($pdfCalls[0]['data']['certificate']->syllabus, 'topic');
        $this->assertSame(['OWN EDITION SESSION'], $topics);
        $this->assertNotContains('FOREIGN EDITION SESSION MUST NOT RENDER', $topics);

        $pdf = Storage::disk('docs')->get($academic->document->path);
        $this->assertStringContainsString('OWN EDITION SESSION', $pdf);
        $this->assertStringNotContainsString('FOREIGN EDITION SESSION MUST NOT RENDER', $pdf);
        $this->assertStringNotContainsString('FOREIGN MANUAL SYLLABUS MUST NOT RENDER', $pdf);
        $this->assertStringNotContainsString('MANUAL DELIVERY SYLLABUS MUST NOT RENDER', $pdf);
        $this->assertStringNotContainsString('BASE SYLLABUS MUST NOT RENDER', $pdf);
    }

    public function test_reference_certificate_html_has_two_intended_page_sections_and_embedded_maia_logo_payload(): void
    {
        $html = view('course-talks.certificates.reference', [
            'certificate' => $this->sampleCertificateViewModel(),
        ])->render();
        $logoPayload = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logo-maia.png')));

        $this->assertSame(1, substr_count($html, '<section class="page certificate-page">'));
        $this->assertSame(1, substr_count($html, '<section class="page syllabus-page">'));
        $this->assertSame(2, substr_count($html, '<section class="page '));
        $this->assertStringContainsString('@page { margin: 0; size: A4 landscape; }', $html);
        $this->assertSame(2, substr_count($html, '<div class="inner-card">'));
        $this->assertSame(2, substr_count($html, 'src="'.$logoPayload.'"'));
        $this->assertStringContainsString('Participant Name', $html);
        $this->assertStringContainsString('Compact Activity', $html);
        $this->assertStringContainsString('01-04.07.26', $html);
        $this->assertStringContainsString('24 horas académicas', $html);
        $this->assertStringNotContainsString('<svg>QR</svg>', $html);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,'.base64_encode('<svg>QR</svg>').'"', $html);
        $this->assertStringContainsString('CERT-123', $html);
        $this->assertStringContainsString('Topic One', $html);
        $this->assertStringContainsString('Topic Two', $html);
    }

    public function test_reference_certificate_html_matches_reference_visual_contract_and_keeps_qr_on_page_one_bottom_left(): void
    {
        $html = view('course-talks.certificates.reference', [
            'certificate' => $this->sampleCertificateViewModel(),
        ])->render();

        $this->assertStringContainsString('.certificate-page { background: #d7d9ff; page-break-after: always; }', $html);
        $this->assertStringContainsString('.syllabus-page { background: #dcefff; }', $html);
        $this->assertStringContainsString('.inner-card { background: #fbfdff; border-radius: 26pt;', $html);
        $this->assertStringContainsString('.doctype { color: #137fc3;', $html);
        $this->assertStringContainsString('.activity { color: #137fc3;', $html);
        $this->assertStringContainsString('.participant .rule { border-top: 1.1pt solid #65758e;', $html);
        $this->assertStringContainsString('Por haber participado en el:', $html);
        $this->assertStringContainsString('Organizado por Maia Consultores, desarrollado en modalidad Virtual,', $html);
        $this->assertStringContainsString('.syllabus-title { color: #137fc3;', $html);
        $this->assertStringContainsString('.table-wrap { border: 1pt solid #8ab8d8; border-radius: 13pt;', $html);
        $this->assertStringContainsString('<th class="speaker">Ponente</th>', $html);
        $this->assertStringContainsString('<div class="page-one-qr" aria-label="Código QR de verificación">', $html);
        $this->assertStringContainsString('.logo { display: block; position: absolute; right: 42pt; top: 31pt; width: 92pt; }', $html);
        $this->assertStringContainsString('.certificate-content { left: 72pt; position: absolute; right: 72pt; text-align: center; top: 76pt; }', $html);
        $this->assertStringContainsString('.page-one-qr { bottom: 37pt; left: 51pt; position: absolute; width: 72pt; }', $html);
        $this->assertStringContainsString('.qr-frame { background: #ffffff; border: 1.6pt solid #102647; border-radius: 4pt; height: 72pt; padding: 5pt; width: 72pt; }', $html);
        $this->assertStringContainsString('.qr-image { display: block; height: 60pt; width: 60pt; }', $html);
        $this->assertStringNotContainsString('bottom: -', $html);
        $this->assertMatchesRegularExpression('/<div class="page-one-qr"[^>]*>\s*<div class="qr-frame"><img class="qr-image" src="data:image\/svg\+xml;base64,[^"]+" alt="Código QR de verificación"><\/div>\s*<\/div>/s', $html);
    }

    public function test_reference_certificate_renders_exactly_two_landscape_a4_pdf_pages_with_dompdf(): void
    {
        $pdf = (new DomPdfRenderer())->render('course-talks.certificates.reference', [
            'certificate' => $this->sampleCertificateViewModel(),
        ]);

        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0(?:\.0+)?\s+0(?:\.0+)?\s+841\.\d+\s+595\.\d+\]/', $pdf);
        $this->assertDoesNotMatchRegularExpression('/\/MediaBox\s*\[0(?:\.0+)?\s+0(?:\.0+)?\s+595\.\d+\s+841\.\d+\]/', $pdf);
    }

    /**
     * Builds the fallback scenario by hand, because the factory
     * `eligibleEnrollment()` seeds BOTH a base syllabus and a session, so through
     * it the three branches of the chain cannot be told apart.
     *
     * @param  list<string>  $baseSyllabus
     * @param  list<string>  $deliverySyllabus
     * @param  list<string>  $sessionTopics
     * @return array{0: CourseEnrollment, 1: User}
     */
    private function enrollmentWithSyllabus(array $baseSyllabus, array $deliverySyllabus, array $sessionTopics = []): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.generate'));
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'name' => 'Curso Avanzado de Saneamiento Ambiental',
            'official_academic_hours' => '24.00',
            'base_syllabus_json' => $baseSyllabus,
        ]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-04',
            'validations_completed_at' => now(),
            'responsible_user_id' => $actor->id,
            'syllabus_override_json' => $deliverySyllabus,
        ]);
        $participant = CourseParticipant::factory()->create(['first_name' => 'Alvaro Segundo', 'last_name' => 'Alama Silva']);
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);

        foreach ($sessionTopics as $index => $topic) {
            CourseSession::factory()->for($edition, 'edition')->create(['topic' => $topic, 'sort_order' => $index + 1]);
        }

        return [CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create([
            'course_enrollment_group_id' => $group->id,
            'state' => CourseEnrollmentState::Completed,
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]), $actor];
    }


    private function sampleCertificateViewModel(): CourseCertificateViewModel
    {
        return new CourseCertificateViewModel(
            title: 'Certificate Title',
            introText: 'Awards this certificate to',
            participant: 'Participant Name',
            activity: 'Compact Activity',
            modality: 'virtual',
            dateRange: '01-04.07.26',
            academicHours: '24',
            issueLocationDate: 'Lima, 4 de julio de 2026',
            certificateCode: 'CERT-123',
            qrSvg: '<svg>QR</svg>',
            signatures: [
                ['name' => 'Signature One', 'role' => 'Academic Direction'],
                ['name' => 'Signature Two', 'role' => 'Academic Coordination'],
            ],
            syllabus: [
                ['class' => 'Clase 1', 'topic' => 'Topic One', 'speaker' => 'Speaker One', 'date' => '01.07.26'],
                ['class' => 'Clase 2', 'topic' => 'Topic Two', 'speaker' => 'Speaker Two', 'date' => '02.07.26'],
            ],
            company: 'Maia Consultores',
        );
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
